<?php

declare(strict_types=1);

namespace Intl\DirectoryLaravel\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function test_directory_laravel_does_not_depend_on_forbidden_domains(): void
    {
        $srcDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));

        $forbiddenImports = [
            'Intl\\ExternalAuth\\',
            'Intl\\ServiceRecipients',
            'Intl\\ServiceRequests',
            'Intl\\DynamicForms',
            'Intl\\Documents',
            'Intl\\Billing',
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
                    "intl/directory-laravel must not import {$forbidden} in {$file->getFilename()}"
                );
            }
        }
    }
}
