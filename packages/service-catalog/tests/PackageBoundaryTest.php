<?php

declare(strict_types=1);

namespace Intl\ServiceCatalog\Tests;

use PHPUnit\Framework\TestCase;

final class PackageBoundaryTest extends TestCase
{
    public function test_core_composer_has_no_framework_or_intl_runtime_dependencies(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['php' => '^8.4'], $composer['require']);
    }

    public function test_core_source_does_not_depend_on_laravel_or_other_intl_packages(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            foreach (['Illuminate\\', 'Spatie\\', 'Intl\\WorkflowLaravel\\', 'Intl\\AuthorizationLaravel\\'] as $namespace) {
                self::assertSame(false, str_contains($source, $namespace), $file->getFilename() . ' references ' . $namespace);
            }
        }
    }
}
