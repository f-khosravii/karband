<?php

declare(strict_types=1);

$rootAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (is_file($rootAutoload)) {
    require $rootAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Intl\\DynamicForms\\';
        if (str_starts_with($class, $prefix)) {
            $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });
}
