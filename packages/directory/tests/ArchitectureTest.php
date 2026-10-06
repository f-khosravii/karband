<?php

declare(strict_types=1);

namespace Intl\Directory\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function test_pure_directory_package_has_no_external_dependencies(): void
    {
        $srcDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));

        $forbiddenImports = [
            'Illuminate\\',
            'Laravel\\',
            'Spatie\\',
            'Intl\\ExternalAuth',
            'Intl\\AuthorizationLaravel',
            'Intl\\ServiceRecipients',
            'Intl\\ServiceRequests',
            'Intl\\Workflow',
        ];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            foreach ($forbiddenImports as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $content,
                    "Pure intl/directory must not import {$forbidden} in {$file->getFilename()}"
                );
            }
        }
    }
}
