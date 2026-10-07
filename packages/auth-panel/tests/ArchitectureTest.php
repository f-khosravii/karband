<?php

declare(strict_types=1);

namespace Karband\AuthPanel\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function test_auth_panel_does_not_depend_on_forbidden_domains(): void
    {
        $srcDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));

        $forbiddenImports = [
            'Karband\\Directory\\',
            'Karband\\DirectoryLaravel\\',
            'Karband\\DirectoryPanel\\',
            'Karband\\Authorization\\',
            'Karband\\AuthorizationLaravel\\',
            'Karband\\ExternalAuth\\',
            'Karband\\ExternalAuthLaravel\\',
            'Karband\\ServiceCatalog\\',
            'Karband\\ServiceCatalogLaravel\\',
            'Karband\\CatalogPanel\\',
            'Karband\\ServiceRecipients',
            'Karband\\ServiceRequests',
            'Karband\\DynamicForms',
            'Karband\\Documents',
            'Karband\\Billing',
            'Karband\\AuthenticationLaravel\\',
            'App\\Models\\User',
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
                    "karband/auth-panel must not import {$forbidden} in {$file->getFilename()}"
                );
            }
        }
    }
}
