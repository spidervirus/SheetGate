<?php

namespace SheetGate;

use PDO;

class Cache
{
    private PDO $pdo;
    private array $config;
    private SchemaWatcher $schemaWatcher;
    private GoogleOAuth $oauth;

    public function __construct(PDO $pdo, array $config, SchemaWatcher $schemaWatcher, GoogleOAuth $oauth)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->schemaWatcher = $schemaWatcher;
        $this->oauth = $oauth;
    }

    /**
     * Get sheet data from cache or fetch from Google with SWR and coalescing.
     *
     * @param array $endpoint
     * @param bool $forceRefresh
     * @return array{headers: array<string>, types: array<string, string>, rows: array<array<string, mixed>>, count: int, cache_status: string, cached_at: int}
     */
    public function getOrFetch(array $endpoint, bool $forceRefresh = false): array
    {
        $endpointId = $endpoint['id'];
        $now = time();
        $coalesceTimeout = (int)($this->config['coalesce_timeout'] ?? 15);

        // Check SQLite cache
        $stmt = $this->pdo->prepare("
            SELECT headers_json, detected_types_json, rows_json, row_count, fetched_at, expires_at, is_fetching, fetch_started_at
            FROM cached_sheets
            WHERE endpoint_id = :id
        ");
        $stmt->execute(['id' => $endpointId]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);

        // Case 1: Fresh cache available and no force refresh
        if (!$forceRefresh && $cached && $now < (int)$cached['expires_at']) {
            return [
                'headers' => json_decode($cached['headers_json'], true) ?: [],
                'types' => json_decode($cached['detected_types_json'], true) ?: [],
                'rows' => json_decode($cached['rows_json'], true) ?: [],
                'count' => (int)$cached['row_count'],
                'cache_status' => 'HIT',
                'cached_at' => (int)$cached['fetched_at'],
            ];
        }

        // Case 2: In-flight request coalescing
        if ($cached && (int)$cached['is_fetching'] === 1 && ($now - (int)$cached['fetch_started_at']) < $coalesceTimeout) {
            // Another process is already fetching. If we have cached data, serve STALE immediately.
            if (!empty($cached['rows_json'])) {
                return [
                    'headers' => json_decode($cached['headers_json'], true) ?: [],
                    'types' => json_decode($cached['detected_types_json'], true) ?: [],
                    'rows' => json_decode($cached['rows_json'], true) ?: [],
                    'count' => (int)$cached['row_count'],
                    'cache_status' => 'STALE',
                    'cached_at' => (int)$cached['fetched_at'],
                ];
            }

            // If initial fetch is in flight, poll briefly (up to 2 seconds)
            for ($i = 0; $i < 20; $i++) {
                usleep(100000); // 100ms
                $stmt->execute(['id' => $endpointId]);
                $poll = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($poll && (int)$poll['is_fetching'] === 0 && !empty($poll['rows_json'])) {
                    return [
                        'headers' => json_decode($poll['headers_json'], true) ?: [],
                        'types' => json_decode($poll['detected_types_json'], true) ?: [],
                        'rows' => json_decode($poll['rows_json'], true) ?: [],
                        'count' => (int)$poll['row_count'],
                        'cache_status' => 'HIT',
                        'cached_at' => (int)$poll['fetched_at'],
                    ];
                }
            }
        }

        // Case 3: Stale-While-Revalidate (SWR)
        // If expired cache exists, we can return stale rows to the user, and revalidate.
        $hasStale = (!$forceRefresh && $cached && !empty($cached['rows_json']));

        // Lock endpoint for coalescing
        $this->acquireFetchLock($endpointId);

        try {
            $fetched = $this->fetchFromSource($endpoint);

            // Schema drift check & lock verification
            $this->schemaWatcher->inspect($endpoint, $fetched['headers'], $fetched['types']);

            // Calculate TTL with minimum 30s floor
            $minTtl = (int)($this->config['min_ttl'] ?? 30);
            $ttl = max($minTtl, (int)($endpoint['cache_ttl'] ?: $this->config['default_ttl']));
            $expiresAt = time() + $ttl;

            // Store in cache
            $save = $this->pdo->prepare("
                INSERT INTO cached_sheets (endpoint_id, headers_json, detected_types_json, rows_json, row_count, fetched_at, expires_at, is_fetching, fetch_started_at)
                VALUES (:id, :headers, :types, :rows, :count, :fetched, :expires, 0, 0)
                ON CONFLICT(endpoint_id) DO UPDATE SET
                    headers_json = :headers,
                    detected_types_json = :types,
                    rows_json = :rows,
                    row_count = :count,
                    fetched_at = :fetched,
                    expires_at = :expires,
                    is_fetching = 0,
                    fetch_started_at = 0
            ");
            $save->execute([
                'id' => $endpointId,
                'headers' => json_encode($fetched['headers']),
                'types' => json_encode($fetched['types']),
                'rows' => json_encode($fetched['rows']),
                'count' => $fetched['count'],
                'fetched' => time(),
                'expires' => $expiresAt,
            ]);

            // Update endpoint status
            $upd = $this->pdo->prepare("
                UPDATE endpoints SET
                    row_count = :count,
                    last_fetched_at = :now,
                    last_status = 'ok',
                    last_error = ''
                WHERE id = :id
            ");
            $upd->execute([
                'count' => $fetched['count'],
                'now' => time(),
                'id' => $endpointId,
            ]);

            return [
                'headers' => $fetched['headers'],
                'types' => $fetched['types'],
                'rows' => $fetched['rows'],
                'count' => $fetched['count'],
                'cache_status' => $hasStale ? 'STALE' : 'MISS',
                'cached_at' => time(),
            ];
        } catch (\Exception $e) {
            $this->releaseFetchLock($endpointId);

            // Update endpoint error status
            $upd = $this->pdo->prepare("UPDATE endpoints SET last_status = 'error', last_error = :err WHERE id = :id");
            $upd->execute(['err' => $e->getMessage(), 'id' => $endpointId]);

            // If stale data is available, fall back to it
            if ($hasStale) {
                return [
                    'headers' => json_decode($cached['headers_json'], true) ?: [],
                    'types' => json_decode($cached['detected_types_json'], true) ?: [],
                    'rows' => json_decode($cached['rows_json'], true) ?: [],
                    'count' => (int)$cached['row_count'],
                    'cache_status' => 'STALE',
                    'cached_at' => (int)$cached['fetched_at'],
                ];
            }

            throw $e;
        }
    }

    /**
     * Fetch raw data from the configured Google source.
     */
    private function fetchFromSource(array $endpoint): array
    {
        $sourceType = $endpoint['source_type'] ?? 'public_url';

        if ($sourceType === 'google_oauth') {
            $token = $this->oauth->getValidAccessToken();
            if (!$token) {
                throw new \RuntimeException('No active Google OAuth token found. Please reconnect Google account in Admin.');
            }
            $tabName = $endpoint['sheet_name'] ?: 'Sheet1';
            return Fetcher::fetchViaApi($endpoint['spreadsheet_id'], $tabName, $token);
        }

        // Default: public Google Sheets CSV export
        $isPublished = str_contains($endpoint['sheet_url'] ?? '', '/pubhtml') || str_contains($endpoint['sheet_url'] ?? '', '/pub?');
        return Fetcher::fetchPublicCsv($endpoint['spreadsheet_id'], $endpoint['sheet_gid'] ?: '0', $isPublished);
    }

    private function acquireFetchLock(string $endpointId): void
    {
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO cached_sheets (endpoint_id, is_fetching, fetch_started_at)
            VALUES (:id, 1, :now)
            ON CONFLICT(endpoint_id) DO UPDATE SET is_fetching = 1, fetch_started_at = :now
        ");
        $stmt->execute(['id' => $endpointId, 'now' => $now]);
    }

    private function releaseFetchLock(string $endpointId): void
    {
        $stmt = $this->pdo->prepare("UPDATE cached_sheets SET is_fetching = 0 WHERE endpoint_id = :id");
        $stmt->execute(['id' => $endpointId]);
    }

    /**
     * Invalidate and clear cache for an endpoint.
     */
    public function invalidate(string $endpointId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM cached_sheets WHERE endpoint_id = :id");
        $stmt->execute(['id' => $endpointId]);
    }
}
