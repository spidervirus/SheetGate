<?php
/**
 * SheetGate Bootstrap
 */

declare(strict_types=1);

spl_autoload_register(function (string $class) {
    $prefix = 'SheetGate\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

$config = require __DIR__ . '/../config.php';
\SheetGate\Database::init($config);

return $config;
