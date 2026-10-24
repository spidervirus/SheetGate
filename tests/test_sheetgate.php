<?php
/**
 * SheetGate Automated Test Suite
 */

declare(strict_types=1);

// Use a temporary test database
putenv('SHEETGATE_APP_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
$testDb = __DIR__ . '/../storage/test.sqlite';
if (file_exists($testDb)) {
    unlink($testDb);
}

$config = require __DIR__ . '/../src/bootstrap.php';
$config['db_file'] = $testDb;

\SheetGate\Database::init($config);
$pdo = \SheetGate\Database::get();

use SheetGate\Fetcher;
use SheetGate\QueryEngine;
use SheetGate\Auth;
use SheetGate\GoogleOAuth;
use SheetGate\SchemaWatcher;
use SheetGate\SchemaLockedException;
use SheetGate\Cache;
use SheetGate\McpServer;

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $testName): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$testName}\n";
        $failed++;
    }
}

echo "=== SheetGate Test Suite Starting ===\n\n";

// --- 1. Fetcher: URL Parsing & SSRF Protection ---
echo "1. Testing Fetcher URL Parsing & SSRF Protection...\n";

$url1 = "https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit#gid=12345";
$parsed1 = Fetcher::parseUrl($url1);
assertTest($parsed1['spreadsheet_id'] === '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms', 'Extract spreadsheet ID from standard URL');
assertTest($parsed1['gid'] === '12345', 'Extract gid from hash fragment');

$url2 = "https://docs.google.com/spreadsheets/d/e/2PACX-1vRabcdef123456/pubhtml?gid=67890";
$parsed2 = Fetcher::parseUrl($url2);
assertTest($parsed2['spreadsheet_id'] === '2PACX-1vRabcdef123456', 'Extract published spreadsheet ID');
assertTest($parsed2['is_published'] === true, 'Detect published sheet');
assertTest($parsed2['gid'] === '67890', 'Extract gid from query parameter');

$ssrfBlocked = false;
try {
    Fetcher::parseUrl("https://evil.attacker.com/spreadsheets/d/123");
} catch (\InvalidArgumentException $e) {
    $ssrfBlocked = true;
}
assertTest($ssrfBlocked, 'SSRF blocked for unauthorized host evil.attacker.com');

$curlSsrfBlocked = false;
try {
    Fetcher::executeSafeCurl("http://169.254.169.254/latest/meta-data");
} catch (\RuntimeException $e) {
    $curlSsrfBlocked = true;
}
assertTest($curlSsrfBlocked, 'SSRF blocked for non-HTTPS and non-Google IP in cURL');

// --- 2. Fetcher: CSV Parsing & Type Detection ---
echo "\n2. Testing CSV Parsing and Type Inference...\n";

$csv = <<<CSV
Product ID,Item Name,Price,Active,Release Date,Empty Col
101,Mechanical Keyboard,"$129.99",true,2024-01-15,
102,Wireless Mouse,"$49.50",yes,2024-02-20,
103,4K Monitor,"$399.00",false,2023-11-05,
104,USB-C Hub,"$25.00",no,2024-03-01,
CSV;

$parsedCsv = Fetcher::parseCsvData($csv);
assertTest(count($parsedCsv['rows']) === 4, 'Parsed 4 rows correctly');
assertTest(in_array('product_id', $parsedCsv['headers'], true), 'Sanitized header product_id');
assertTest(in_array('item_name', $parsedCsv['headers'], true), 'Sanitized header item_name');
assertTest($parsedCsv['types']['product_id'] === 'number', 'Inferred product_id as number');
assertTest($parsedCsv['types']['price'] === 'number', 'Inferred price currency as number');
assertTest($parsedCsv['types']['active'] === 'boolean', 'Inferred active as boolean');
assertTest($parsedCsv['types']['release_date'] === 'date', 'Inferred release_date as date');
assertTest($parsedCsv['rows'][0]['price'] === 129.99, 'Cast currency "$129.99" to float 129.99');
assertTest($parsedCsv['rows'][0]['active'] === true, 'Cast boolean "true" to true');
assertTest($parsedCsv['rows'][1]['active'] === true, 'Cast boolean "yes" to true');
assertTest($parsedCsv['rows'][2]['active'] === false, 'Cast boolean "false" to false');

// --- 3. QueryEngine: Filters, Sorting, Search, Aggregates ---
echo "\n3. Testing QueryEngine...\n";

$rows = $parsedCsv['rows'];
$headers = $parsedCsv['headers'];

// Equality filter
$resEq = QueryEngine::process($rows, ['product_id' => '102'], $headers);
assertTest(count($resEq['data']) === 1 && $resEq['data'][0]['item_name'] === 'Wireless Mouse', 'Filter ?product_id=102');

// Greater than filter
$resGt = QueryEngine::process($rows, ['price' => 'gt.100'], $headers);
assertTest(count($resGt['data']) === 2, 'Filter ?price=gt.100 (2 items)');

// Contains filter
$resContains = QueryEngine::process($rows, ['item_name' => 'contains.key'], $headers);
assertTest(count($resContains['data']) === 1 && $resContains['data'][0]['product_id'] === 101, 'Filter ?item_name=contains.key');

// Full-text search
$resSearch = QueryEngine::process($rows, ['search' => 'hub'], $headers);
assertTest(count($resSearch['data']) === 1 && $resSearch['data'][0]['product_id'] === 104, 'Search ?search=hub');

// Sorting
$resSortDesc = QueryEngine::process($rows, ['sort' => 'price', 'order' => 'desc'], $headers);
assertTest($resSortDesc['data'][0]['price'] === 399.00, 'Sort ?sort=price&order=desc highest price first');

// Pagination
$resPage = QueryEngine::process($rows, ['limit' => '2', 'offset' => '1'], $headers);
assertTest(count($resPage['data']) === 2 && $resPage['total'] === 4 && $resPage['offset'] === 1, 'Pagination limit=2, offset=1');

// Field projection
$resFields = QueryEngine::process($rows, ['fields' => 'product_id,price'], $headers);
assertTest(array_keys($resFields['data'][0]) === ['product_id', 'price'], 'Column projection ?fields=product_id,price');

// Aggregates
$resAgg = QueryEngine::process($rows, ['aggregate' => 'count,sum:price,avg:price,min:price,max:price'], $headers);
$aggs = $resAgg['aggregates'];
assertTest($aggs['count'] === 4, 'Aggregate count = 4');
assertTest($aggs['sum_price'] === 603.49, 'Aggregate sum:price = 603.49');
assertTest($aggs['min_price'] === 25.00, 'Aggregate min:price = 25.00');
assertTest($aggs['max_price'] === 399.00, 'Aggregate max:price = 399.00');

// Group By
$resGroup = QueryEngine::process($rows, ['group_by' => 'active', 'aggregate' => 'sum:price,count'], $headers);
assertTest(count($resGroup['groups']) === 2, 'Group by active created 2 groups (true and false)');

// --- 4. Auth & Security ---
echo "\n4. Testing Auth, API Keys, Libsodium, and Rate Limiting...\n";

$auth = new Auth($pdo, $config);

// Password setup & verification
$auth->setAdminPassword('Secret123!');
assertTest($auth->isAdminConfigured() === true, 'Admin password marked configured');
assertTest($auth->loginAdmin('Secret123!') === true, 'Admin login with correct password');
assertTest($auth->loginAdmin('WrongPassword') === false, 'Admin login fails with wrong password');

// API Key generation & verification
$pdo->exec("INSERT INTO endpoints (id, name, spreadsheet_id, created_at, updated_at) VALUES ('test_ep', 'Test Endpoint', 'sheet123', strftime('%s','now'), strftime('%s','now'))");
$apiKey = $auth->generateApiKey('test_ep', 'Production App');
assertTest(str_starts_with($apiKey['plain_key'], 'sg_live_'), 'Generated API key starts with sg_live_');
$validKey = $auth->validateEndpointKey('test_ep', 'Bearer ' . $apiKey['plain_key']);
assertTest($validKey === true, 'Validate Bearer API key successfully');
$invalidKey = $auth->validateEndpointKey('test_ep', 'Bearer sg_live_invalid');
assertTest($invalidKey === false, 'Reject invalid Bearer API key');

// Libsodium SecretBox Encryption
$secretData = json_encode(['access_token' => 'ya29.xyz', 'refresh_token' => '1//abc']);
$encrypted = $auth->encrypt($secretData);
assertTest($encrypted !== $secretData, 'SecretBox ciphertext is encrypted and base64 encoded');
$decrypted = $auth->decrypt($encrypted);
assertTest($decrypted === $secretData, 'SecretBox decrypted data matches original plaintext');

// Rate limiting
$rl1 = $auth->checkRateLimit('ep_demo', '192.168.1.10', 2);
assertTest($rl1['allowed'] === true && $rl1['remaining'] === 1, 'Rate limit check 1 allowed');
$rl2 = $auth->checkRateLimit('ep_demo', '192.168.1.10', 2);
assertTest($rl2['allowed'] === true && $rl2['remaining'] === 0, 'Rate limit check 2 allowed');
$rl3 = $auth->checkRateLimit('ep_demo', '192.168.1.10', 2);
assertTest($rl3['allowed'] === false, 'Rate limit check 3 blocked (limit exceeded)');

// --- 5. Schema Drift Protection ---
echo "\n5. Testing Schema Drift & Lock Protection...\n";

$schemaWatcher = new SchemaWatcher($pdo);
$endpointRow = [
    'id' => 'ep_drift_test',
    'name' => 'Drift Test Endpoint',
    'webhook_url' => '',
    'schema_locked' => 0,
];
$pdo->exec("INSERT INTO endpoints (id, name, spreadsheet_id, created_at, updated_at) VALUES ('ep_drift_test', 'Drift Test Endpoint', 'sheet456', strftime('%s','now'), strftime('%s','now'))");

// Snapshot baseline
$drift1 = $schemaWatcher->inspect($endpointRow, ['id', 'name', 'email'], ['id' => 'number', 'name' => 'text', 'email' => 'text']);
assertTest($drift1['has_drift'] === false, 'Baseline snapshot saved without drift');

// Inspect identical
$drift2 = $schemaWatcher->inspect($endpointRow, ['id', 'name', 'email'], ['id' => 'number', 'name' => 'text', 'email' => 'text']);
assertTest($drift2['has_drift'] === false, 'Identical schema produces no drift');

// Column added & dropped
$drift3 = $schemaWatcher->inspect($endpointRow, ['id', 'full_name', 'email', 'phone'], ['id' => 'number', 'full_name' => 'text', 'email' => 'text', 'phone' => 'text']);
assertTest($drift3['has_drift'] === true, 'Drift detected when columns added/dropped');
assertTest(in_array('phone', $drift3['drift']['added_columns'], true), 'Detected added column "phone"');
assertTest(in_array('name', $drift3['drift']['dropped_columns'], true), 'Detected dropped column "name"');

// Schema Lock Mode
$lockedEndpoint = $endpointRow;
$lockedEndpoint['schema_locked'] = 1;

$schemaLockTriggered = false;
try {
    $schemaWatcher->inspect($lockedEndpoint, ['id', 'email'], ['id' => 'number', 'email' => 'text']);
} catch (SchemaLockedException $sle) {
    $schemaLockTriggered = true;
}
assertTest($schemaLockTriggered, 'SchemaLockedException thrown when schema changes on locked endpoint');

// --- 6. MCP Server Protocol ---
echo "\n6. Testing Model Context Protocol (MCP) Server...\n";

$oauth = new GoogleOAuth($pdo, $config, $auth);
$cache = new Cache($pdo, $config, $schemaWatcher, $oauth);
$mcp = new McpServer($cache, $oauth);

// Test initialize
$initPayload = json_encode([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => ['protocolVersion' => '2024-11-05']
]);
$initRes = $mcp->handle($endpointRow, $initPayload);
assertTest($initRes['result']['protocolVersion'] === '2024-11-05', 'MCP initialize protocolVersion 2024-11-05');
assertTest($initRes['result']['capabilities']['tools']['listChanged'] === false, 'MCP capabilities returned');

// Test tools/list
$listPayload = json_encode([
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'tools/list'
]);
$listRes = $mcp->handle($endpointRow, $listPayload);
$toolNames = array_column($listRes['result']['tools'], 'name');
assertTest(count($toolNames) === 3, 'MCP tools/list returns exactly 3 tools');
assertTest(in_array('list_tabs', $toolNames, true), 'MCP includes tool list_tabs');
assertTest(in_array('get_schema', $toolNames, true), 'MCP includes tool get_schema');
assertTest(in_array('query_rows', $toolNames, true), 'MCP includes tool query_rows');

// Save mock cached sheet so MCP tools can query
$mockSave = $pdo->prepare("
    INSERT INTO cached_sheets (endpoint_id, headers_json, detected_types_json, rows_json, row_count, fetched_at, expires_at)
    VALUES (:id, :headers, :types, :rows, :count, :now, :exp)
");
$mockSave->execute([
    'id' => $endpointRow['id'],
    'headers' => json_encode($headers),
    'types' => json_encode($parsedCsv['types']),
    'rows' => json_encode($rows),
    'count' => count($rows),
    'now' => time(),
    'exp' => time() + 3600,
]);

// Test tools/call get_schema
$callSchema = json_encode([
    'jsonrpc' => '2.0',
    'id' => 3,
    'method' => 'tools/call',
    'params' => [
        'name' => 'get_schema',
        'arguments' => ['include_samples' => true]
    ]
]);
$schemaRes = $mcp->handle($endpointRow, $callSchema);
assertTest(isset($schemaRes['result']['content'][0]['text']), 'MCP get_schema returned content text');
$schemaJson = json_decode($schemaRes['result']['content'][0]['text'], true);
assertTest($schemaJson['total_rows'] === 4, 'MCP get_schema reported 4 total rows');

// Test tools/call query_rows with search & sort
$callQuery = json_encode([
    'jsonrpc' => '2.0',
    'id' => 4,
    'method' => 'tools/call',
    'params' => [
        'name' => 'query_rows',
        'arguments' => [
            'search' => 'Keyboard',
            'limit' => 5
        ]
    ]
]);
$queryRes = $mcp->handle($endpointRow, $callQuery);
$queryJson = json_decode($queryRes['result']['content'][0]['text'], true);
assertTest(count($queryJson['data']) === 1 && $queryJson['data'][0]['item_name'] === 'Mechanical Keyboard', 'MCP query_rows returned matched record');

// Clean up test database
$pdo = null;
foreach ([$testDb, $testDb . '-wal', $testDb . '-shm'] as $f) {
    if (file_exists($f)) {
        @unlink($f);
    }
}

echo "\n============================================\n";
echo "Test Results: {$passed} Passed, {$failed} Failed.\n";
echo "============================================\n";

if ($failed > 0) {
    exit(1);
}
