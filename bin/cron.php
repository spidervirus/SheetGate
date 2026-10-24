#!/usr/bin/env php
<?php
/**
 * SheetGate CLI Cron Script
 * Usage: php bin/cron.php [--force]
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

$config = require_once __DIR__ . '/../src/bootstrap.php';

use SheetGate\Database;
use SheetGate\Auth;
use SheetGate\GoogleOAuth;
use SheetGate\SchemaWatcher;
use SheetGate\Cache;

echo "[" . date('Y-m-d H:i:s') . "] SheetGate Cron Worker Starting...\n";

try {
    $pdo = Database::get();

    $auth = new Auth($pdo, $config);
    $oauth = new GoogleOAuth($pdo, $config, $auth);
    $schemaWatcher = new SchemaWatcher($pdo);
    $cache = new Cache($pdo, $config, $schemaWatcher, $oauth);

    $force = in_array('--force', $argv, true);

    $stmt = $pdo->query("SELECT * FROM endpoints WHERE last_status != 'disabled'");
    $endpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($endpoints) . " endpoint(s) to evaluate.\n";

    $refreshed = 0;
    $errors = 0;

    foreach ($endpoints as $ep) {
        $now = time();
        $ttl = max(30, (int)$ep['cache_ttl']);
        $lastFetched = (int)$ep['last_fetched_at'];

        if ($force || $lastFetched === 0 || ($now - $lastFetched) >= $ttl) {
            echo "  -> Refreshing endpoint [{$ep['id']}] '{$ep['name']}'... ";
            try {
                $res = $cache->getOrFetch($ep, true);
                echo "OK ({$res['count']} rows)\n";
                $refreshed++;
            } catch (\Exception $e) {
                echo "FAILED: " . $e->getMessage() . "\n";
                $errors++;
            }
        } else {
            $remaining = $ttl - ($now - $lastFetched);
            echo "  -> Endpoint [{$ep['id']}] '{$ep['name']}' is fresh (TTL expires in {$remaining}s). Skipping.\n";
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Finished. Refreshed: {$refreshed}, Errors: {$errors}.\n";
} catch (\Throwable $t) {
    echo "Fatal cron error: " . $t->getMessage() . "\n";
    exit(1);
}
