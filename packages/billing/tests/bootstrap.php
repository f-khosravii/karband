<?php

declare(strict_types=1);

$vendor = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Intl\\Billing\\';
        if (str_starts_with($class, $prefix)) {
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; }
        }
    });
}
