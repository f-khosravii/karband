<?php

declare(strict_types=1);

// Use the root monorepo Composer autoloader if present; keep the package portable.
$rootAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
$packageAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($rootAutoload)) {
    require_once $rootAutoload;
} elseif (is_file($packageAutoload)) {
    require_once $packageAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Intl\\Workflow\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
