<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PackageBoundaryTest extends TestCase
{
    public function test_core_is_framework_independent(): void
    {
        $meta = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys($meta['require']) as $name) {
            self::assertFalse(str_starts_with($name, 'illuminate/'));
            self::assertFalse(str_starts_with($name, 'laravel/'));
            self::assertFalse(str_starts_with($name, 'karband/'));
        }
    }
}
