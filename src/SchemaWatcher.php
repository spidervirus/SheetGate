<?php

namespace SheetGate;

use PDO;

class SchemaLockedException extends \RuntimeException
{
    private array $drift;

    public function __construct(string $message, array $drift = [])
    {
        parent::__construct($message, 409);
        $this->drift = $drift;
    }

    public function getDrift(): array
    {
        return $this->drift;
    }
}

class SchemaWatcher
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inspect columns and detected types against the last snapshot.
     *
     * @param array $endpoint
     * @param array<string> $newHeaders
     * @param array<string, string> $newTypes
     * @return array{has_drift: bool, drift: array}
     * @throws SchemaLockedException
     */
    public function inspect(array $endpoint, array $newHeaders, array $newTypes): array
    {
        $endpointId = $endpoint['id'];

        // Get latest snapshot
        $stmt = $this->pdo->prepare("
            SELECT columns_json, types_json, version
            FROM schema_snapshots
            WHERE endpoint_id = :id
            ORDER BY version DESC LIMIT 1
        ");
        $stmt->execute(['id' => $endpointId]);
        $lastSnapshot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lastSnapshot) {
            // First time seeing this sheet: record baseline snapshot
            $this->saveSnapshot($endpointId, $newHeaders, $newTypes, 1);
            return ['has_drift' => false, 'drift' => []];
        }

        $oldHeaders = json_decode($lastSnapshot['columns_json'], true) ?: [];
        $oldTypes = json_decode($lastSnapshot['types_json'], true) ?: [];
        $currentVersion = (int)$lastSnapshot['version'];

        $added = array_values(array_diff($newHeaders, $oldHeaders));
        $dropped = array_values(array_diff($oldHeaders, $newHeaders));

        $typesChanged = [];
        foreach ($newHeaders as $col) {
            if (isset($oldTypes[$col]) && isset($newTypes[$col]) && $oldTypes[$col] !== $newTypes[$col]) {
                $typesChanged[$col] = [
                    'from' => $oldTypes[$col],
                    'to' => $newTypes[$col],
                ];
            }
        }

        // Detect potential renames (heuristic: 1 dropped + 1 added with matching index)
        $potentialRenames = [];
        if (count($added) === 1 && count($dropped) === 1) {
            $potentialRenames[] = [
                'from' => $dropped[0],
                'to' => $added[0],
            ];
        }

        $hasDrift = !empty($added) || !empty($dropped) || !empty($typesChanged);

        $driftDetails = [
            'added_columns' => $added,
            'dropped_columns' => $dropped,
            'types_changed' => $typesChanged,
            'potential_renames' => $potentialRenames,
            'old_columns' => $oldHeaders,
            'new_columns' => $newHeaders,
            'detected_at' => time(),
        ];

        if ($hasDrift) {
            // Log drift
            $logStmt = $this->pdo->prepare("
                INSERT INTO schema_drift_logs (endpoint_id, event_type, details_json, created_at)
                VALUES (:endpoint_id, :event_type, :details_json, :created_at)
            ");
            $logStmt->execute([
                'endpoint_id' => $endpointId,
                'event_type' => !empty($dropped) ? 'columns_dropped' : (!empty($added) ? 'columns_added' : 'types_changed'),
                'details_json' => json_encode($driftDetails),
                'created_at' => time(),
            ]);

            // Dispatch webhook if configured
            if (!empty($endpoint['webhook_url'])) {
                $this->dispatchWebhook($endpoint['webhook_url'], [
                    'event' => 'schema_drift',
                    'endpoint_id' => $endpointId,
                    'endpoint_name' => $endpoint['name'],
                    'drift' => $driftDetails,
                    'timestamp' => time(),
                ]);
            }

            // If schema is locked, prevent serving changed shape
            if (!empty($endpoint['schema_locked'])) {
                throw new SchemaLockedException(
                    "Schema drift detected: column structure has changed and schema is locked for endpoint '{$endpoint['name']}'.",
                    $driftDetails
                );
            }

            // Save new snapshot version
            $this->saveSnapshot($endpointId, $newHeaders, $newTypes, $currentVersion + 1);
        }

        return [
            'has_drift' => $hasDrift,
            'drift' => $driftDetails,
        ];
    }

    private function saveSnapshot(string $endpointId, array $headers, array $types, int $version): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO schema_snapshots (endpoint_id, columns_json, types_json, version, created_at)
            VALUES (:id, :cols, :types, :ver, :created)
        ");
        $stmt->execute([
            'id' => $endpointId,
            'cols' => json_encode($headers),
            'types' => json_encode($types),
            'ver' => $version,
            'created' => time(),
        ]);
    }

    /**
     * Dispatch webhook with low timeout to not block requests.
     */
    private function dispatchWebhook(string $webhookUrl, array $payload): void
    {
        $parsed = parse_url($webhookUrl);
        if (!$parsed || empty($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
            return;
        }

        $json = json_encode($payload);
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: SheetGate-Webhook/1.0',
            ],
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        @curl_exec($ch);
        unset($ch);
    }
}
