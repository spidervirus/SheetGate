<?php

namespace SheetGate;

use PDO;

class GoogleOAuth
{
    private PDO $pdo;
    private array $config;
    private Auth $auth;

    public function __construct(PDO $pdo, array $config, Auth $auth)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->auth = $auth;
    }

    /**
     * Get OAuth client config, allowing overrides saved in app_settings.
     */
    public function getOAuthConfig(): array
    {
        $defaults = $this->config['google'] ?? [];

        // Check if overridden in database
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'google_%'");
        $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'client_id' => $dbSettings['google_client_id'] ?? $defaults['client_id'] ?? '',
            'client_secret' => $dbSettings['google_client_secret'] ?? $defaults['client_secret'] ?? '',
            'redirect_uri' => $dbSettings['google_redirect_uri'] ?? $defaults['redirect_uri'] ?? '',
            'api_key' => $dbSettings['google_api_key'] ?? $defaults['api_key'] ?? '',
            'app_id' => $dbSettings['google_app_id'] ?? $defaults['app_id'] ?? '',
            'scopes' => $defaults['scopes'] ?? [
                'https://www.googleapis.com/auth/spreadsheets.readonly',
                'https://www.googleapis.com/auth/drive.readonly',
            ],
        ];
    }

    /**
     * Save OAuth client credentials.
     */
    public function saveOAuthConfig(array $settings): void
    {
        $keys = ['client_id', 'client_secret', 'redirect_uri', 'api_key', 'app_id'];
        $stmt = $this->pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (:k, :v)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = :v
        ");

        foreach ($keys as $k) {
            if (isset($settings[$k])) {
                $stmt->execute(['k' => "google_{$k}", 'v' => trim($settings[$k])]);
            }
        }
    }

    /**
     * Generate Google OAuth authorization URL.
     */
    public function getAuthUrl(string $redirectUri): string
    {
        $cfg = $this->getOAuthConfig();
        if (empty($cfg['client_id'])) {
            throw new \RuntimeException('Google Client ID is not configured.');
        }

        $state = bin2hex(random_bytes(16));
        Auth::startSession();
        $_SESSION['oauth_state'] = $state;

        $params = [
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $cfg['scopes']),
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access and refresh tokens.
     */
    public function handleCallback(string $code, string $state, string $redirectUri): array
    {
        Auth::startSession();
        $storedState = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);

        if (!$storedState || !hash_equals($storedState, $state)) {
            throw new \RuntimeException('Invalid OAuth state parameter. Possible CSRF attack.');
        }

        $cfg = $this->getOAuthConfig();
        if (empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            throw new \RuntimeException('Google Client ID or Client Secret missing.');
        }

        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'code' => $code,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? 'Token exchange failed.';
            throw new \RuntimeException("Google OAuth Error: {$err}");
        }

        // Fetch user email
        $userEmail = $this->fetchUserEmail($data['access_token']);

        // Calculate expiration timestamp
        $data['expires_at'] = time() + ((int)($data['expires_in'] ?? 3600) - 60);

        // Encrypt tokens at rest with Libsodium
        $encrypted = $this->auth->encrypt(json_encode($data));

        // Save in oauth_tokens table
        $stmt = $this->pdo->prepare("
            INSERT INTO oauth_tokens (provider, account_email, encrypted_tokens, created_at, updated_at)
            VALUES ('google', :email, :tokens, :now, :now)
        ");
        $stmt->execute([
            'email' => $userEmail,
            'tokens' => $encrypted,
            'now' => time(),
        ]);

        return [
            'email' => $userEmail,
            'expires_at' => $data['expires_at'],
        ];
    }

    /**
     * Get a valid access token, automatically refreshing if expired.
     */
    public function getValidAccessToken(?int $tokenId = null): ?string
    {
        $query = "SELECT id, account_email, encrypted_tokens FROM oauth_tokens WHERE provider = 'google' ";
        if ($tokenId) {
            $query .= "AND id = " . (int)$tokenId . " ";
        }
        $query .= "ORDER BY updated_at DESC LIMIT 1";

        $tokenRow = $this->pdo->query($query)->fetch(PDO::FETCH_ASSOC);
        if (!$tokenRow) {
            return null;
        }

        $decryptedJson = $this->auth->decrypt($tokenRow['encrypted_tokens']);
        if (!$decryptedJson) {
            return null;
        }

        $tokens = json_decode($decryptedJson, true);
        if (!$tokens || empty($tokens['access_token'])) {
            return null;
        }

        // Check if access token is still fresh
        if (isset($tokens['expires_at']) && time() < $tokens['expires_at']) {
            return $tokens['access_token'];
        }

        // Token expired, use refresh token if available
        if (empty($tokens['refresh_token'])) {
            return null;
        }

        $cfg = $this->getOAuthConfig();
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $tokens['refresh_token'],
            'grant_type' => 'refresh_token',
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        $refreshed = json_decode((string)$response, true);
        if ($httpCode !== 200 || empty($refreshed['access_token'])) {
            return null;
        }

        $tokens['access_token'] = $refreshed['access_token'];
        $tokens['expires_at'] = time() + ((int)($refreshed['expires_in'] ?? 3600) - 60);

        // Update stored encrypted token
        $newEncrypted = $this->auth->encrypt(json_encode($tokens));
        $upd = $this->pdo->prepare("UPDATE oauth_tokens SET encrypted_tokens = :tokens, updated_at = :now WHERE id = :id");
        $upd->execute([
            'tokens' => $newEncrypted,
            'now' => time(),
            'id' => $tokenRow['id'],
        ]);

        return $tokens['access_token'];
    }

    /**
     * Get connected Google accounts info.
     */
    public function getConnectedAccounts(): array
    {
        $stmt = $this->pdo->query("SELECT id, provider, account_email, created_at, updated_at FROM oauth_tokens ORDER BY updated_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Disconnect Google account.
     */
    public function disconnectAccount(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM oauth_tokens WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    private function fetchUserEmail(string $accessToken): string
    {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        unset($ch);

        $data = json_decode((string)$res, true);
        return $data['email'] ?? 'Google Account';
    }
}
