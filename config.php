<?php
/**
 * SheetGate - Configuration
 *
 * All values can be overridden here or via environment variables.
 */

// Generate or read application master key (used for encrypting OAuth tokens with libsodium)
$appKey = getenv('SHEETGATE_APP_KEY');
if (!$appKey) {
    // 32-byte key in hex
    $keyFile = __DIR__ . '/storage/.app_key';
    if (file_exists($keyFile)) {
        $appKey = trim(file_get_contents($keyFile));
    } else {
        $appKey = bin2hex(random_bytes(32));
        if (!is_dir(__DIR__ . '/storage')) {
            @mkdir(__DIR__ . '/storage', 0755, true);
        }
        @file_put_contents($keyFile, $appKey);
    }
}

return [
    'app_name' => getenv('SHEETGATE_APP_NAME') ?: 'SheetGate',
    'app_env' => getenv('SHEETGATE_APP_ENV') ?: 'production',
    'app_key' => $appKey,
    'app_url' => getenv('SHEETGATE_APP_URL') ?: '', // Auto-detected if empty

    // Storage and Database
    'storage_path' => __DIR__ . '/storage',
    'db_file' => __DIR__ . '/storage/app.sqlite',

    // Cache rules
    'default_ttl' => (int)(getenv('SHEETGATE_DEFAULT_TTL') ?: 300), // 5 minutes
    'min_ttl' => 30, // 30 seconds floor to protect Google
    'coalesce_timeout' => 15, // seconds before breaking an orphaned fetch lock

    // Google OAuth 2.0 & Picker settings
    'google' => [
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
        'api_key' => getenv('GOOGLE_API_KEY') ?: '', // Picker API key
        'app_id' => getenv('GOOGLE_APP_ID') ?: '',   // Google Project Number
        'scopes' => [
            'https://www.googleapis.com/auth/spreadsheets.readonly',
            'https://www.googleapis.com/auth/drive.readonly',
        ],
    ],

    // Default rate limit (requests per minute per IP or per API key)
    'default_rate_limit' => (int)(getenv('SHEETGATE_RATE_LIMIT') ?: 60),

    // Allowed origins for CORS (default '*' allows any origin, can be customized per endpoint)
    'default_cors' => '*',
];
