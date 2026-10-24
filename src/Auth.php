<?php

namespace SheetGate;

use PDO;

class Auth
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * Start session safely if not started.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                session_set_cookie_params([
                    'lifetime' => 86400 * 7, // 7 days
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            @session_start();
        }
    }

    /**
     * Check if admin password has been set up in app_settings.
     */
    public function isAdminConfigured(): bool
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'admin_password'");
        $stmt->execute();
        $hash = $stmt->fetchColumn();
        return !empty($hash);
    }

    /**
     * Set the initial admin password or change it.
     */
    public function setAdminPassword(string $newPassword): void
    {
        if (strlen($newPassword) < 6) {
            throw new \InvalidArgumentException('Password must be at least 6 characters long.');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('admin_password', :val)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = :val");
        $stmt->execute(['val' => $hash]);
    }

    /**
     * Verify admin password and login.
     */
    public function loginAdmin(string $password): bool
    {
        self::startSession();

        $stmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'admin_password'");
        $stmt->execute();
        $hash = $stmt->fetchColumn();

        if (!$hash) {
            return false;
        }

        if (password_verify($password, $hash)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_login_time'] = time();
            return true;
        }

        return false;
    }

    /**
     * Check if current session is authenticated as admin.
     */
    public static function isAdminLoggedIn(): bool
    {
        self::startSession();
        return !empty($_SESSION['admin_logged_in']);
    }

    /**
     * Logout admin.
     */
    public static function logoutAdmin(): void
    {
        self::startSession();
        $_SESSION['admin_logged_in'] = false;
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_login_time']);
        session_destroy();
    }

    /**
     * CSRF token generation and verification.
     */
    public static function getCsrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool
    {
        self::startSession();
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate a new API key for an endpoint.
     * Returns: ['plain_key' => 'sg_live_...', 'id' => ..., 'hint' => '...']
     */
    public function generateApiKey(string $endpointId, string $keyName): array
    {
        $rawBytes = random_bytes(24);
        $plainKey = 'sg_live_' . bin2hex($rawBytes);
        $keyPrefix = 'sg_live_';
        $keyHint = substr($plainKey, -4);
        $keyHash = hash('sha256', $plainKey);

        $stmt = $this->pdo->prepare("
            INSERT INTO endpoint_keys (endpoint_id, name, key_hash, key_prefix, key_hint, created_at)
            VALUES (:endpoint_id, :name, :key_hash, :key_prefix, :key_hint, :created_at)
        ");
        $stmt->execute([
            'endpoint_id' => $endpointId,
            'name' => $keyName ?: 'API Key',
            'key_hash' => $keyHash,
            'key_prefix' => $keyPrefix,
            'key_hint' => $keyHint,
            'created_at' => time(),
        ]);

        return [
            'id' => (int)$this->pdo->lastInsertId(),
            'plain_key' => $plainKey,
            'hint' => $keyHint,
            'name' => $keyName,
        ];
    }

    /**
     * Validate Bearer API key for an endpoint.
     */
    public function validateEndpointKey(string $endpointId, ?string $authHeader): bool
    {
        if (empty($authHeader)) {
            return false;
        }

        // Bearer sg_live_...
        if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return false;
        }

        $plainKey = trim($matches[1]);
        $keyHash = hash('sha256', $plainKey);

        $stmt = $this->pdo->prepare("
            SELECT id FROM endpoint_keys
            WHERE endpoint_id = :endpoint_id AND key_hash = :hash
            LIMIT 1
        ");
        $stmt->execute([
            'endpoint_id' => $endpointId,
            'hash' => $keyHash,
        ]);

        $keyId = $stmt->fetchColumn();
        if ($keyId) {
            // Update last_used_at
            $upd = $this->pdo->prepare("UPDATE endpoint_keys SET last_used_at = :now WHERE id = :id");
            $upd->execute(['now' => time(), 'id' => $keyId]);
            return true;
        }

        return false;
    }

    /**
     * Libsodium SecretBox Encryption for OAuth tokens and sensitive data at rest.
     */
    public function encrypt(string $plaintext): string
    {
        $appKeyHex = $this->config['app_key'] ?? '';
        $rawKey = hex2bin($appKeyHex);

        if (strlen($rawKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $rawKey = hash('sha256', $appKeyHex, true);
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $rawKey);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encoded): ?string
    {
        $data = base64_decode($encoded, true);
        if ($data === false) {
            return null;
        }

        $nonceBytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($data) < $nonceBytes) {
            return null;
        }

        $nonce = substr($data, 0, $nonceBytes);
        $ciphertext = substr($data, $nonceBytes);

        $appKeyHex = $this->config['app_key'] ?? '';
        $rawKey = hex2bin($appKeyHex);
        if (strlen($rawKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $rawKey = hash('sha256', $appKeyHex, true);
        }

        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $rawKey);
        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Check rate limit for an endpoint and client IP.
     * Returns: ['allowed' => bool, 'limit' => int, 'remaining' => int, 'reset' => int]
     */
    public function checkRateLimit(string $endpointId, string $clientIp, int $limitPerMin): array
    {
        if ($limitPerMin <= 0) {
            return ['allowed' => true, 'limit' => 0, 'remaining' => 9999, 'reset' => 0];
        }

        $now = time();
        $bucketKey = "rl:{$endpointId}:{$clientIp}";

        // Clean expired rate limit records periodically
        if (mt_rand(1, 100) === 1) {
            $this->pdo->exec("DELETE FROM rate_limits WHERE reset_at < {$now}");
        }

        $stmt = $this->pdo->prepare("SELECT hit_count, reset_at FROM rate_limits WHERE bucket_key = :k");
        $stmt->execute(['k' => $bucketKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['reset_at'] <= $now) {
            // New 60 second window
            $resetAt = $now + 60;
            $ins = $this->pdo->prepare("
                INSERT INTO rate_limits (bucket_key, hit_count, reset_at)
                VALUES (:k, 1, :reset_at)
                ON CONFLICT(bucket_key) DO UPDATE SET hit_count = 1, reset_at = :reset_at
            ");
            $ins->execute(['k' => $bucketKey, 'reset_at' => $resetAt]);

            return [
                'allowed' => true,
                'limit' => $limitPerMin,
                'remaining' => max(0, $limitPerMin - 1),
                'reset' => $resetAt,
            ];
        }

        $currentHits = (int)$row['hit_count'] + 1;
        $resetAt = (int)$row['reset_at'];

        $upd = $this->pdo->prepare("UPDATE rate_limits SET hit_count = :c WHERE bucket_key = :k");
        $upd->execute(['c' => $currentHits, 'k' => $bucketKey]);

        $allowed = $currentHits <= $limitPerMin;
        $remaining = max(0, $limitPerMin - $currentHits);

        return [
            'allowed' => $allowed,
            'limit' => $limitPerMin,
            'remaining' => $remaining,
            'reset' => $resetAt,
        ];
    }

    /**
     * Get client IP safely.
     */
    public static function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ips = explode(',', $_SERVER[$h]);
                return trim($ips[0]);
            }
        }
        return '127.0.0.1';
    }
}
