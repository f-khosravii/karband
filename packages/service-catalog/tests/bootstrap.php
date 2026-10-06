<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $name): void {
        $prefix = 'Intl\\ServiceCatalog\\';
        if (str_starts_with($name, $prefix)) {
            require __DIR__ . '/../src/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
        }
    });
}
