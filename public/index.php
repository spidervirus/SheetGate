<?php
/**
 * SheetGate - Fast, Cached Google Sheets REST API & MCP Server
 * Front Controller & HTTP Router
 */

declare(strict_types=1);

// Serve static assets directly if running via PHP built-in web server
if (php_sapi_name() === 'cli-server') {
    $reqUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $targetFile = __DIR__ . $reqUri;
    if ($reqUri !== '/' && file_exists($targetFile) && !is_dir($targetFile)) {
        return false;
    }
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

$config = require_once __DIR__ . '/../src/bootstrap.php';

use SheetGate\Database;
use SheetGate\Auth;
use SheetGate\GoogleOAuth;
use SheetGate\SchemaWatcher;
use SheetGate\Cache;
use SheetGate\McpServer;
use SheetGate\Router;

try {
    $pdo = Database::get();

    $auth = new Auth($pdo, $config);
    $oauth = new GoogleOAuth($pdo, $config, $auth);
    $schemaWatcher = new SchemaWatcher($pdo);
    $cache = new Cache($pdo, $config, $schemaWatcher, $oauth);
    $mcp = new McpServer($cache, $oauth);

    $router = new Router($pdo, $config, $auth, $cache, $mcp, $oauth, $schemaWatcher);
    $router->dispatch();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'SheetGate Internal Error: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
