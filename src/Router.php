<?php

namespace SheetGate;

use PDO;

class Router
{
    private array $routes = [];
    private PDO $pdo;
    private array $config;
    private Auth $auth;
    private Cache $cache;
    private McpServer $mcp;
    private GoogleOAuth $oauth;
    private SchemaWatcher $schemaWatcher;

    public function __construct(
        PDO $pdo,
        array $config,
        Auth $auth,
        Cache $cache,
        McpServer $mcp,
        GoogleOAuth $oauth,
        SchemaWatcher $schemaWatcher
    ) {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->auth = $auth;
        $this->cache = $cache;
        $this->mcp = $mcp;
        $this->oauth = $oauth;
        $this->schemaWatcher = $schemaWatcher;
    }

    /**
     * Dispatch incoming HTTP request.
     */
    public function dispatch(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Normalize URI: trim trailing slash except root
        $uri = rtrim($uri, '/');
        if ($uri === '') {
            $uri = '/';
        }

        // Global OPTIONS preflight check
        if ($method === 'OPTIONS') {
            $this->handleCorsPreflight();
            return;
        }

        try {
            // Static assets (/assets/css/..., /assets/js/...)
            if (str_starts_with($uri, '/assets/')) {
                $this->serveStaticAsset($uri);
                return;
            }

            // Static Admin UI
            if ($uri === '/' || $uri === '/admin') {
                $this->serveAdminUi();
                return;
            }

            // Google OAuth callback
            if ($uri === '/oauth/callback') {
                $this->handleOAuthCallback();
                return;
            }

            // Cron endpoint
            if ($uri === '/api/cron') {
                $this->handleCron();
                return;
            }

            // REST API endpoints: /api/{endpoint_id}
            if (preg_match('#^/api/([a-zA-Z0-9_-]+)/refresh$#', $uri, $m)) {
                $this->handleApiRefresh($m[1], $method);
                return;
            }

            if (preg_match('#^/api/([a-zA-Z0-9_-]+)$#', $uri, $m)) {
                $this->handleApiQuery($m[1], $method);
                return;
            }

            // MCP endpoint: /mcp/{endpoint_id}
            if (preg_match('#^/mcp/([a-zA-Z0-9_-]+)$#', $uri, $m)) {
                $this->handleMcp($m[1], $method);
                return;
            }

            // Admin API endpoints: /admin/api/...
            if (str_starts_with($uri, '/admin/api/')) {
                $this->handleAdminApi($uri, $method);
                return;
            }

            // Fallback 404
            $this->jsonResponse(['error' => 'Route not found', 'path' => $uri], 404);
        } catch (\Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            $this->jsonResponse([
                'error' => $e->getMessage(),
                'status' => $status,
            ], $status);
        }
    }

    /**
     * Handle REST API query for a sheet endpoint.
     */
    private function handleApiQuery(string $endpointId, string $method): void
    {
        if ($method !== 'GET') {
            $this->jsonResponse(['error' => 'Method not allowed. Use GET.'], 405);
            return;
        }

        $startTime = microtime(true);
        $endpoint = $this->getEndpoint($endpointId);
        if (!$endpoint) {
            $this->jsonResponse(['error' => "Endpoint '{$endpointId}' not found."], 404);
            return;
        }

        // Apply CORS headers
        $this->applyCorsHeaders($endpoint['cors_origins'] ?: '*');

        // Security check: API key required if endpoint is protected
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if ($endpoint['visibility'] === 'protected') {
            if (!$this->auth->validateEndpointKey($endpointId, $authHeader)) {
                $this->jsonResponse([
                    'error' => 'Unauthorized: This endpoint requires a valid Bearer API key.',
                    'hint' => 'Provide header Authorization: Bearer <your_api_key>',
                ], 401);
                return;
            }
        }

        // Rate limiting check
        $clientIp = Auth::getClientIp();
        $rateLimit = (int)($endpoint['rate_limit_per_min'] ?? $this->config['default_rate_limit']);
        $rl = $this->auth->checkRateLimit($endpointId, $clientIp, $rateLimit);

        header("X-RateLimit-Limit: {$rl['limit']}");
        header("X-RateLimit-Remaining: {$rl['remaining']}");
        header("X-RateLimit-Reset: {$rl['reset']}");

        if (!$rl['allowed']) {
            $retryAfter = max(1, $rl['reset'] - time());
            header("Retry-After: {$retryAfter}");
            $this->logRequest($endpointId, $clientIp, "/api/{$endpointId}", $method, 429, 'RATE_LIMITED', (microtime(true) - $startTime) * 1000);
            $this->jsonResponse(['error' => 'Too Many Requests: Rate limit exceeded. Try again in ' . $retryAfter . ' seconds.'], 429);
            return;
        }

        try {
            // Check cache & fetch
            $sheet = $this->cache->getOrFetch($endpoint);

            // Execute QueryEngine
            $queryParams = $_GET;
            $result = QueryEngine::process($sheet['rows'], $queryParams, $sheet['headers']);

            // Cache headers
            header("X-Cache: {$sheet['cache_status']}");
            header("X-Cached-At: " . date('c', $sheet['cached_at']));
            header("X-Total-Count: {$result['total']}");

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logRequest($endpointId, $clientIp, "/api/{$endpointId}", $method, 200, $sheet['cache_status'], $duration);

            $this->jsonResponse($result, 200);
        } catch (SchemaLockedException $sle) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->logRequest($endpointId, $clientIp, "/api/{$endpointId}", $method, 409, 'SCHEMA_LOCKED', $duration);
            $this->jsonResponse([
                'error' => $sle->getMessage(),
                'drift' => $sle->getDrift(),
            ], 409);
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->logRequest($endpointId, $clientIp, "/api/{$endpointId}", $method, 500, 'ERROR', $duration);
            $this->jsonResponse([
                'error' => 'Failed to process request: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle manual endpoint cache refresh.
     */
    private function handleApiRefresh(string $endpointId, string $method): void
    {
        if ($method !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed. Use POST.'], 405);
            return;
        }

        $endpoint = $this->getEndpoint($endpointId);
        if (!$endpoint) {
            $this->jsonResponse(['error' => "Endpoint '{$endpointId}' not found."], 404);
            return;
        }

        // Must be admin or have valid endpoint API key
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        $isAdmin = Auth::isAdminLoggedIn();
        $hasKey = $this->auth->validateEndpointKey($endpointId, $authHeader);

        if (!$isAdmin && !$hasKey) {
            $this->jsonResponse(['error' => 'Unauthorized: Refresh requires admin authentication or a valid API key.'], 401);
            return;
        }

        try {
            $refreshed = $this->cache->getOrFetch($endpoint, true);
            $this->jsonResponse([
                'success' => true,
                'message' => 'Cache successfully refreshed.',
                'row_count' => $refreshed['count'],
                'fetched_at' => date('c', $refreshed['cached_at']),
                'cache_status' => $refreshed['cache_status'],
            ], 200);
        } catch (\Exception $e) {
            $this->jsonResponse(['error' => 'Refresh failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handle MCP Protocol JSON-RPC 2.0 requests.
     */
    private function handleMcp(string $endpointId, string $method): void
    {
        $endpoint = $this->getEndpoint($endpointId);
        if (!$endpoint) {
            $this->jsonResponse(['error' => "Endpoint '{$endpointId}' not found."], 404);
            return;
        }

        $this->applyCorsHeaders('*');

        // Check authentication if protected
        if ($endpoint['visibility'] === 'protected') {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
            $urlKey = $_GET['api_key'] ?? null;
            if ($urlKey) {
                $authHeader = "Bearer {$urlKey}";
            }

            if (!$this->auth->validateEndpointKey($endpointId, $authHeader)) {
                $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => -32000,
                        'message' => 'Unauthorized: MCP endpoint requires a valid API key.',
                    ],
                ], 401);
                return;
            }
        }

        // GET /mcp/{endpoint_id} returns MCP Server metadata and connect instructions
        if ($method === 'GET') {
            $appUrl = $this->getBaseUrl();
            $mcpUrl = "{$appUrl}/mcp/{$endpointId}";
            $this->jsonResponse([
                'name' => 'sheetgate-' . $endpoint['id'],
                'description' => "SheetGate Model Context Protocol (MCP) server for {$endpoint['name']}",
                'endpoint_id' => $endpoint['id'],
                'mcp_url' => $mcpUrl,
                'tools' => $this->mcp->getToolsSchema($endpoint),
                'claude_desktop_config' => [
                    'mcpServers' => [
                        'sheetgate-' . $endpoint['id'] => [
                            'command' => 'npx',
                            'args' => ['-y', 'mcp-remote', $mcpUrl],
                        ],
                    ],
                ],
                'cursor_config' => [
                    'name' => 'sheetgate-' . $endpoint['id'],
                    'url' => $mcpUrl,
                    'type' => 'http',
                ],
            ]);
            return;
        }

        if ($method === 'POST') {
            $rawInput = file_get_contents('php://input');
            $response = $this->mcp->handle($endpoint, (string)$rawInput);
            $this->jsonResponse($response);
            return;
        }

        $this->jsonResponse(['error' => 'Method not allowed. Use POST or GET.'], 405);
    }

    /**
     * Handle Admin API endpoints.
     */
    private function handleAdminApi(string $uri, string $method): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // Auth status and login do not require active session
        if ($uri === '/admin/api/auth/status') {
            $this->jsonResponse([
                'configured' => $this->auth->isAdminConfigured(),
                'logged_in' => Auth::isAdminLoggedIn(),
                'csrf_token' => Auth::getCsrfToken(),
            ]);
            return;
        }

        if ($uri === '/admin/api/auth/setup' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            if ($this->auth->isAdminConfigured()) {
                $this->jsonResponse(['error' => 'Admin password has already been configured.'], 400);
                return;
            }
            $password = trim($body['password'] ?? '');
            if (strlen($password) < 6) {
                $this->jsonResponse(['error' => 'Password must be at least 6 characters.'], 400);
                return;
            }
            $this->auth->setAdminPassword($password);
            $this->auth->loginAdmin($password);
            $this->jsonResponse(['success' => true, 'csrf_token' => Auth::getCsrfToken()]);
            return;
        }

        if ($uri === '/admin/api/auth/login' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $password = trim($body['password'] ?? '');
            if ($this->auth->loginAdmin($password)) {
                $this->jsonResponse(['success' => true, 'csrf_token' => Auth::getCsrfToken()]);
            } else {
                $this->jsonResponse(['error' => 'Invalid password.'], 401);
            }
            return;
        }

        if ($uri === '/admin/api/auth/logout' && $method === 'POST') {
            Auth::logoutAdmin();
            $this->jsonResponse(['success' => true]);
            return;
        }

        // All other admin routes require active admin session
        if (!Auth::isAdminLoggedIn()) {
            $this->jsonResponse(['error' => 'Unauthorized: Admin session required.'], 401);
            return;
        }

        // Validate CSRF token for state-changing methods
        if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            if (!Auth::verifyCsrfToken($csrf)) {
                $this->jsonResponse(['error' => 'Invalid or missing CSRF token.'], 403);
                return;
            }
        }

        // --- Endpoints CRUD ---
        if ($uri === '/admin/api/endpoints' && $method === 'GET') {
            $stmt = $this->pdo->query("
                SELECT e.*,
                    (SELECT COUNT(*) FROM endpoint_keys k WHERE k.endpoint_id = e.id) AS keys_count,
                    (SELECT COUNT(*) FROM schema_drift_logs d WHERE d.endpoint_id = e.id) AS drift_count
                FROM endpoints e
                ORDER BY e.created_at DESC
            ");
            $endpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->jsonResponse(['endpoints' => $endpoints]);
            return;
        }

        // Test sheet URL without saving
        if ($uri === '/admin/api/endpoints/test' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $sheetUrl = trim($body['sheet_url'] ?? '');
            if (empty($sheetUrl)) {
                $this->jsonResponse(['error' => 'Google Sheet URL is required.'], 400);
                return;
            }

            try {
                $parsed = Fetcher::parseUrl($sheetUrl);
                $fetched = Fetcher::fetchPublicCsv($parsed['spreadsheet_id'], $parsed['gid'], $parsed['is_published']);
                $this->jsonResponse([
                    'success' => true,
                    'spreadsheet_id' => $parsed['spreadsheet_id'],
                    'gid' => $parsed['gid'],
                    'row_count' => $fetched['count'],
                    'columns' => array_map(fn($col) => [
                        'name' => $col,
                        'type' => $fetched['types'][$col] ?? 'text',
                    ], $fetched['headers']),
                    'preview_rows' => array_slice($fetched['rows'], 0, 5),
                ]);
            } catch (\Exception $e) {
                $this->jsonResponse(['error' => $e->getMessage()], 400);
            }
            return;
        }

        // Create Endpoint
        if ($uri === '/admin/api/endpoints' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $name = trim($body['name'] ?? '');
            $sourceType = $body['source_type'] ?? 'public_url';

            if (empty($name)) {
                $this->jsonResponse(['error' => 'Endpoint name is required.'], 400);
                return;
            }

            // Generate clean ID or slug
            $customId = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower(trim($body['id'] ?? '')));
            if ($customId === '') {
                $customId = 'ep_' . substr(bin2hex(random_bytes(6)), 0, 8);
            }

            // Check duplicate ID
            $check = $this->pdo->prepare("SELECT COUNT(*) FROM endpoints WHERE id = :id");
            $check->execute(['id' => $customId]);
            if ($check->fetchColumn() > 0) {
                $this->jsonResponse(['error' => "Endpoint ID '{$customId}' already exists."], 400);
                return;
            }

            $spreadsheetId = '';
            $sheetGid = '0';
            $sheetUrl = '';

            if ($sourceType === 'public_url') {
                $sheetUrl = trim($body['sheet_url'] ?? '');
                try {
                    $parsed = Fetcher::parseUrl($sheetUrl);
                    $spreadsheetId = $parsed['spreadsheet_id'];
                    $sheetGid = $parsed['gid'];
                } catch (\Exception $e) {
                    $this->jsonResponse(['error' => 'Invalid Google Sheets URL: ' . $e->getMessage()], 400);
                    return;
                }
            } else {
                $spreadsheetId = trim($body['spreadsheet_id'] ?? '');
                $sheetGid = trim($body['sheet_gid'] ?? '0');
                if (empty($spreadsheetId)) {
                    $this->jsonResponse(['error' => 'Spreadsheet ID is required for OAuth sheet.'], 400);
                    return;
                }
            }

            $ttl = max(30, (int)($body['cache_ttl'] ?? $this->config['default_ttl']));
            $rateLimit = max(0, (int)($body['rate_limit_per_min'] ?? $this->config['default_rate_limit']));
            $visibility = in_array($body['visibility'] ?? '', ['public', 'protected'], true) ? $body['visibility'] : 'public';
            $cors = trim($body['cors_origins'] ?? '*');
            $locked = !empty($body['schema_locked']) ? 1 : 0;
            $webhook = trim($body['webhook_url'] ?? '');
            $sheetName = trim($body['sheet_name'] ?? '');

            $stmt = $this->pdo->prepare("
                INSERT INTO endpoints (
                    id, name, description, source_type, sheet_url, spreadsheet_id, sheet_gid, sheet_name,
                    visibility, cache_ttl, rate_limit_per_min, cors_origins, schema_locked, webhook_url,
                    created_at, updated_at
                ) VALUES (
                    :id, :name, :description, :source_type, :sheet_url, :spreadsheet_id, :sheet_gid, :sheet_name,
                    :visibility, :cache_ttl, :rate_limit_per_min, :cors_origins, :schema_locked, :webhook_url,
                    :now, :now
                )
            ");
            $now = time();
            $stmt->execute([
                'id' => $customId,
                'name' => $name,
                'description' => trim($body['description'] ?? ''),
                'source_type' => $sourceType,
                'sheet_url' => $sheetUrl,
                'spreadsheet_id' => $spreadsheetId,
                'sheet_gid' => $sheetGid,
                'sheet_name' => $sheetName,
                'visibility' => $visibility,
                'cache_ttl' => $ttl,
                'rate_limit_per_min' => $rateLimit,
                'cors_origins' => $cors,
                'schema_locked' => $locked,
                'webhook_url' => $webhook,
                'now' => $now,
            ]);

            // Attempt initial fetch in background/fast
            $newEndpoint = $this->getEndpoint($customId);
            try {
                $this->cache->getOrFetch($newEndpoint);
            } catch (\Exception $e) {
                // Log error but allow creation
            }

            $this->jsonResponse(['success' => true, 'id' => $customId, 'endpoint' => $this->getEndpoint($customId)], 201);
            return;
        }

        // Single Endpoint operations: /admin/api/endpoints/{id}
        if (preg_match('#^/admin/api/endpoints/([a-zA-Z0-9_-]+)$#', $uri, $m)) {
            $endpointId = $m[1];
            $endpoint = $this->getEndpoint($endpointId);
            if (!$endpoint) {
                $this->jsonResponse(['error' => 'Endpoint not found'], 404);
                return;
            }

            if ($method === 'GET') {
                // Get keys
                $keysStmt = $this->pdo->prepare("SELECT id, name, key_prefix, key_hint, last_used_at, created_at FROM endpoint_keys WHERE endpoint_id = :id ORDER BY created_at DESC");
                $keysStmt->execute(['id' => $endpointId]);
                $keys = $keysStmt->fetchAll(PDO::FETCH_ASSOC);

                // Get cache metadata
                $cacheStmt = $this->pdo->prepare("SELECT headers_json, detected_types_json, row_count, fetched_at, expires_at FROM cached_sheets WHERE endpoint_id = :id");
                $cacheStmt->execute(['id' => $endpointId]);
                $cached = $cacheStmt->fetch(PDO::FETCH_ASSOC);

                // Get drift logs
                $driftStmt = $this->pdo->prepare("SELECT id, event_type, details_json, created_at FROM schema_drift_logs WHERE endpoint_id = :id ORDER BY created_at DESC LIMIT 10");
                $driftStmt->execute(['id' => $endpointId]);
                $driftLogs = $driftStmt->fetchAll(PDO::FETCH_ASSOC);

                // Get recent request logs
                $logStmt = $this->pdo->prepare("SELECT id, ip_address, path, method, status_code, cache_status, response_time_ms, created_at FROM request_logs WHERE endpoint_id = :id ORDER BY created_at DESC LIMIT 25");
                $logStmt->execute(['id' => $endpointId]);
                $recentLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

                $this->jsonResponse([
                    'endpoint' => $endpoint,
                    'keys' => $keys,
                    'cached_schema' => $cached ? [
                        'headers' => json_decode($cached['headers_json'], true) ?: [],
                        'types' => json_decode($cached['detected_types_json'], true) ?: [],
                        'row_count' => (int)$cached['row_count'],
                        'fetched_at' => (int)$cached['fetched_at'],
                        'expires_at' => (int)$cached['expires_at'],
                    ] : null,
                    'drift_logs' => array_map(function ($d) {
                        $d['details'] = json_decode($d['details_json'], true) ?: [];
                        unset($d['details_json']);
                        return $d;
                    }, $driftLogs),
                    'recent_logs' => $recentLogs,
                ]);
                return;
            }

            if ($method === 'PUT') {
                $body = json_decode(file_get_contents('php://input'), true) ?: [];
                $name = trim($body['name'] ?? $endpoint['name']);
                $desc = trim($body['description'] ?? $endpoint['description']);
                $ttl = max(30, (int)($body['cache_ttl'] ?? $endpoint['cache_ttl']));
                $rateLimit = max(0, (int)($body['rate_limit_per_min'] ?? $endpoint['rate_limit_per_min']));
                $visibility = in_array($body['visibility'] ?? '', ['public', 'protected'], true) ? $body['visibility'] : $endpoint['visibility'];
                $cors = trim($body['cors_origins'] ?? $endpoint['cors_origins']);
                $locked = isset($body['schema_locked']) ? (!empty($body['schema_locked']) ? 1 : 0) : $endpoint['schema_locked'];
                $webhook = trim($body['webhook_url'] ?? $endpoint['webhook_url']);
                $sheetGid = trim($body['sheet_gid'] ?? $endpoint['sheet_gid']);
                $sheetName = trim($body['sheet_name'] ?? $endpoint['sheet_name']);

                $stmt = $this->pdo->prepare("
                    UPDATE endpoints SET
                        name = :name,
                        description = :desc,
                        cache_ttl = :ttl,
                        rate_limit_per_min = :rl,
                        visibility = :visibility,
                        cors_origins = :cors,
                        schema_locked = :locked,
                        webhook_url = :webhook,
                        sheet_gid = :gid,
                        sheet_name = :sname,
                        updated_at = :now
                    WHERE id = :id
                ");
                $stmt->execute([
                    'name' => $name,
                    'desc' => $desc,
                    'ttl' => $ttl,
                    'rl' => $rateLimit,
                    'visibility' => $visibility,
                    'cors' => $cors,
                    'locked' => $locked,
                    'webhook' => $webhook,
                    'gid' => $sheetGid,
                    'sname' => $sheetName,
                    'now' => time(),
                    'id' => $endpointId,
                ]);

                $this->jsonResponse(['success' => true, 'endpoint' => $this->getEndpoint($endpointId)]);
                return;
            }

            if ($method === 'DELETE') {
                $del = $this->pdo->prepare("DELETE FROM endpoints WHERE id = :id");
                $del->execute(['id' => $endpointId]);
                $this->jsonResponse(['success' => true]);
                return;
            }
        }

        // Keys management
        if (preg_match('#^/admin/api/endpoints/([a-zA-Z0-9_-]+)/keys$#', $uri, $m) && $method === 'POST') {
            $endpointId = $m[1];
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $keyName = trim($body['name'] ?? 'Production Key');
            $newKey = $this->auth->generateApiKey($endpointId, $keyName);
            $this->jsonResponse(['success' => true, 'key' => $newKey], 201);
            return;
        }

        if (preg_match('#^/admin/api/endpoints/([a-zA-Z0-9_-]+)/keys/([0-9]+)$#', $uri, $m) && $method === 'DELETE') {
            $keyId = (int)$m[2];
            $del = $this->pdo->prepare("DELETE FROM endpoint_keys WHERE id = :id AND endpoint_id = :eid");
            $del->execute(['id' => $keyId, 'eid' => $m[1]]);
            $this->jsonResponse(['success' => true]);
            return;
        }

        // Drift acknowledge
        if (preg_match('#^/admin/api/endpoints/([a-zA-Z0-9_-]+)/drift/acknowledge$#', $uri, $m) && $method === 'POST') {
            $endpointId = $m[1];
            $endpoint = $this->getEndpoint($endpointId);
            $cacheStmt = $this->pdo->prepare("SELECT headers_json, detected_types_json FROM cached_sheets WHERE endpoint_id = :id");
            $cacheStmt->execute(['id' => $endpointId]);
            $cached = $cacheStmt->fetch(PDO::FETCH_ASSOC);

            if ($cached) {
                $headers = json_decode($cached['headers_json'], true) ?: [];
                $types = json_decode($cached['detected_types_json'], true) ?: [];
                // Save as latest snapshot
                $stmt = $this->pdo->prepare("
                    INSERT INTO schema_snapshots (endpoint_id, columns_json, types_json, version, created_at)
                    VALUES (:id, :cols, :types, 1000 + (SELECT COALESCE(MAX(version),0) FROM schema_snapshots WHERE endpoint_id = :id), :now)
                ");
                $stmt->execute(['id' => $endpointId, 'cols' => json_encode($headers), 'types' => json_encode($types), 'now' => time()]);
            }

            // Clear drift logs
            $delDrift = $this->pdo->prepare("DELETE FROM schema_drift_logs WHERE endpoint_id = :id");
            $delDrift->execute(['id' => $endpointId]);

            $this->jsonResponse(['success' => true, 'message' => 'Schema acknowledged and snapshot updated.']);
            return;
        }

        // Global stats & recent logs
        if ($uri === '/admin/api/logs' && $method === 'GET') {
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
            $stmt = $this->pdo->prepare("SELECT * FROM request_logs ORDER BY created_at DESC LIMIT :l");
            $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute summary stats
            $totalReqs = $this->pdo->query("SELECT COUNT(*) FROM request_logs")->fetchColumn();
            $hits = $this->pdo->query("SELECT COUNT(*) FROM request_logs WHERE cache_status IN ('HIT', 'STALE')")->fetchColumn();
            $hitRatio = $totalReqs > 0 ? round(($hits / $totalReqs) * 100, 1) : 100.0;
            $avgTime = $this->pdo->query("SELECT AVG(response_time_ms) FROM request_logs")->fetchColumn() ?: 0;

            $this->jsonResponse([
                'logs' => $logs,
                'stats' => [
                    'total_requests' => (int)$totalReqs,
                    'cache_hit_ratio' => $hitRatio,
                    'avg_latency_ms' => round((float)$avgTime, 2),
                ],
            ]);
            return;
        }

        // Google OAuth Settings & Accounts
        if ($uri === '/admin/api/oauth/config' && $method === 'GET') {
            $cfg = $this->oauth->getOAuthConfig();
            // Don't leak full secret
            $maskedSecret = !empty($cfg['client_secret']) ? substr($cfg['client_secret'], 0, 4) . '••••••••' : '';
            $this->jsonResponse([
                'client_id' => $cfg['client_id'],
                'client_secret_configured' => !empty($cfg['client_secret']),
                'masked_secret' => $maskedSecret,
                'redirect_uri' => $cfg['redirect_uri'] ?: ($this->getBaseUrl() . '/oauth/callback'),
                'api_key' => $cfg['api_key'],
                'app_id' => $cfg['app_id'],
            ]);
            return;
        }

        if ($uri === '/admin/api/oauth/config' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $this->oauth->saveOAuthConfig($body);
            $this->jsonResponse(['success' => true]);
            return;
        }

        if ($uri === '/admin/api/oauth/accounts' && $method === 'GET') {
            $accounts = $this->oauth->getConnectedAccounts();
            $this->jsonResponse(['accounts' => $accounts]);
            return;
        }

        if (preg_match('#^/admin/api/oauth/accounts/([0-9]+)$#', $uri, $m) && $method === 'DELETE') {
            $id = (int)$m[1];
            $this->oauth->disconnectAccount($id);
            $this->jsonResponse(['success' => true]);
            return;
        }

        if ($uri === '/admin/api/oauth/auth-url' && $method === 'GET') {
            $redirectUri = $this->getBaseUrl() . '/oauth/callback';
            try {
                $authUrl = $this->oauth->getAuthUrl($redirectUri);
                $this->jsonResponse(['auth_url' => $authUrl]);
            } catch (\Exception $e) {
                $this->jsonResponse(['error' => $e->getMessage()], 400);
            }
            return;
        }

        $this->jsonResponse(['error' => 'Admin API endpoint not found.'], 404);
    }

    /**
     * Handle Google OAuth 2.0 callback redirect.
     */
    private function handleOAuthCallback(): void
    {
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;
        $error = $_GET['error'] ?? null;

        if ($error) {
            header('Location: /admin?oauth_error=' . urlencode($error));
            exit;
        }

        if (!$code || !$state) {
            header('Location: /admin?oauth_error=' . urlencode('Missing OAuth parameters'));
            exit;
        }

        $redirectUri = $this->getBaseUrl() . '/oauth/callback';
        try {
            $this->oauth->handleCallback($code, $state, $redirectUri);
            header('Location: /admin?oauth_success=1');
            exit;
        } catch (\Exception $e) {
            header('Location: /admin?oauth_error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Handle scheduled background cron refresh.
     */
    private function handleCron(): void
    {
        $cronKey = $_GET['key'] ?? null;
        $configuredKey = $this->config['app_key'] ?? '';

        if (!empty($configuredKey) && $cronKey !== $configuredKey && !Auth::isAdminLoggedIn()) {
            $this->jsonResponse(['error' => 'Unauthorized cron request'], 401);
            return;
        }

        $stmt = $this->pdo->query("SELECT * FROM endpoints WHERE last_status != 'disabled'");
        $endpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $refreshed = 0;
        $errors = [];

        foreach ($endpoints as $ep) {
            try {
                $this->cache->getOrFetch($ep, true);
                $refreshed++;
            } catch (\Exception $e) {
                $errors[$ep['id']] = $e->getMessage();
            }
        }

        $this->jsonResponse([
            'success' => true,
            'total_endpoints' => count($endpoints),
            'refreshed' => $refreshed,
            'errors' => $errors,
            'timestamp' => time(),
        ]);
    }

    /**
     * Serve static assets (/assets/...) with correct MIME types and caching.
     */
    private function serveStaticAsset(string $uri): void
    {
        $publicDir = realpath(__DIR__ . '/../public');
        $target = realpath($publicDir . $uri);

        if (!$target || !str_starts_with($target, $publicDir) || !is_file($target)) {
            $this->jsonResponse(['error' => 'Asset not found', 'path' => $uri], 404);
            return;
        }

        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $mimes = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'json' => 'application/json; charset=utf-8',
            'ico' => 'image/x-icon',
        ];

        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($target);
        exit;
    }

    /**
     * Serve single page Admin HTML UI.
     */
    private function serveAdminUi(): void
    {
        $adminFile = __DIR__ . '/../public/admin/index.html';
        if (file_exists($adminFile)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($adminFile);
            return;
        }
        echo '<!DOCTYPE html><html><body><h1>SheetGate</h1><p>Admin UI loading...</p></body></html>';
    }

    private function getEndpoint(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM endpoints WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function logRequest(
        string $endpointId,
        string $ip,
        string $path,
        string $method,
        int $statusCode,
        string $cacheStatus,
        float $durationMs
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO request_logs (endpoint_id, ip_address, path, method, status_code, cache_status, response_time_ms, created_at)
                VALUES (:eid, :ip, :path, :method, :status, :cache, :duration, :now)
            ");
            $stmt->execute([
                'eid' => $endpointId,
                'ip' => $ip,
                'path' => $path,
                'method' => $method,
                'status' => $statusCode,
                'cache' => $cacheStatus,
                'duration' => round($durationMs, 2),
                'now' => time(),
            ]);
        } catch (\Exception $e) {
            // Ignore logging failures to not disrupt response
        }
    }

    private function applyCorsHeaders(string $origins): void
    {
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        $allowed = '*';

        if ($origins !== '*') {
            $allowedList = array_map('trim', explode(',', $origins));
            if (in_array($requestOrigin, $allowedList, true)) {
                $allowed = $requestOrigin;
            } else {
                $allowed = $allowedList[0] ?? '*';
            }
        }

        header("Access-Control-Allow-Origin: {$allowed}");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-RateLimit-Limit');
        header('Access-Control-Expose-Headers: X-Cache, X-Cached-At, X-Total-Count, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');
        header('Access-Control-Max-Age: 86400');
    }

    private function handleCorsPreflight(): void
    {
        $this->applyCorsHeaders('*');
        http_response_code(204);
        exit;
    }

    private function jsonResponse(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    private function getBaseUrl(): string
    {
        if (!empty($this->config['app_url'])) {
            return rtrim($this->config['app_url'], '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
        return "{$scheme}://{$host}";
    }
}
