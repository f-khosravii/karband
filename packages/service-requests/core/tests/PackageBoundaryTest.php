<?php

declare(strict_types=1);

namespace Karband\ServiceRequests\Tests;

use PHPUnit\Framework\TestCase;

final class PackageBoundaryTest extends TestCase
{
    public function test_core_requires_no_framework_or_other_platform_packages(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['php' => '^8.4'], $json['require']);
    }

    public function test_core_sources_do_not_import_laravel_or_database_packages(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
            $source = (string) file_get_contents((string) $file);
            self::assertStringNotContainsString('Illuminate\\', $source);
            self::assertStringNotContainsString('Spatie\\', $source);
        }
    }
}
