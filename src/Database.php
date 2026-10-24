<?php

namespace SheetGate;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;
    private static ?array $config = null;

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function get(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config.php';
        }

        $dbPath = self::$config['db_file'] ?? (__DIR__ . '/../storage/app.sqlite');
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0755, true);
        }

        $dsn = 'sqlite:' . $dbPath;
        try {
            self::$instance = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Optimization for SQLite read/write concurrency
            self::$instance->exec('PRAGMA journal_mode = WAL;');
            self::$instance->exec('PRAGMA synchronous = NORMAL;');
            self::$instance->exec('PRAGMA foreign_keys = ON;');
            self::$instance->exec('PRAGMA busy_timeout = 5000;');

            // Run migrations
            self::migrate(self::$instance);

            return self::$instance;
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection error: ' . $e->getMessage(), 500, $e);
        }
    }

    private static function migrate(PDO $pdo): void
    {
        $schema = <<<SQL
        CREATE TABLE IF NOT EXISTS endpoints (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            source_type TEXT NOT NULL DEFAULT 'public_url', -- 'public_url' | 'google_oauth'
            sheet_url TEXT DEFAULT '',
            spreadsheet_id TEXT NOT NULL,
            sheet_gid TEXT NOT NULL DEFAULT '0',
            sheet_name TEXT DEFAULT '',
            visibility TEXT NOT NULL DEFAULT 'public', -- 'public' | 'protected'
            cache_ttl INTEGER NOT NULL DEFAULT 300,
            rate_limit_per_min INTEGER NOT NULL DEFAULT 60,
            cors_origins TEXT NOT NULL DEFAULT '*',
            schema_locked INTEGER NOT NULL DEFAULT 0,
            webhook_url TEXT DEFAULT '',
            last_fetched_at INTEGER DEFAULT 0,
            last_status TEXT DEFAULT 'ok',
            last_error TEXT DEFAULT '',
            row_count INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS endpoint_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint_id TEXT NOT NULL,
            name TEXT NOT NULL,
            key_hash TEXT NOT NULL,
            key_prefix TEXT NOT NULL,
            key_hint TEXT NOT NULL,
            last_used_at INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS cached_sheets (
            endpoint_id TEXT PRIMARY KEY,
            headers_json TEXT NOT NULL DEFAULT '[]',
            detected_types_json TEXT NOT NULL DEFAULT '{}',
            rows_json TEXT NOT NULL DEFAULT '[]',
            row_count INTEGER NOT NULL DEFAULT 0,
            fetched_at INTEGER NOT NULL DEFAULT 0,
            expires_at INTEGER NOT NULL DEFAULT 0,
            is_fetching INTEGER NOT NULL DEFAULT 0,
            fetch_started_at INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS schema_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint_id TEXT NOT NULL,
            columns_json TEXT NOT NULL,
            types_json TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS schema_drift_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint_id TEXT NOT NULL,
            event_type TEXT NOT NULL,
            details_json TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS oauth_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL DEFAULT 'google',
            account_email TEXT DEFAULT '',
            encrypted_tokens TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS request_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint_id TEXT NOT NULL,
            ip_address TEXT DEFAULT '',
            path TEXT NOT NULL,
            method TEXT NOT NULL,
            status_code INTEGER NOT NULL,
            cache_status TEXT NOT NULL, -- 'HIT', 'MISS', 'STALE', 'BYPASS', 'ERROR'
            response_time_ms REAL NOT NULL,
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS rate_limits (
            bucket_key TEXT PRIMARY KEY,
            hit_count INTEGER NOT NULL DEFAULT 0,
            reset_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_endpoints_created ON endpoints(created_at);
        CREATE INDEX IF NOT EXISTS idx_endpoint_keys_hash ON endpoint_keys(key_hash);
        CREATE INDEX IF NOT EXISTS idx_endpoint_keys_endpoint ON endpoint_keys(endpoint_id);
        CREATE INDEX IF NOT EXISTS idx_request_logs_endpoint ON request_logs(endpoint_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_schema_drift_endpoint ON schema_drift_logs(endpoint_id, created_at);
SQL;

        $pdo->exec($schema);
    }
}
