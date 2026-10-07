<?php

declare(strict_types=1);

namespace Karband\AuthenticationLaravel\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function test_authentication_laravel_does_not_depend_on_forbidden_domains(): void
    {
        $srcDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));

        $forbiddenImports = [
            'Karband\\Directory\\',
            'Karband\\DirectoryLaravel\\',
            'Karband\\Authorization\\',
            'Karband\\AuthorizationLaravel\\',
            'Karband\\ExternalAuth\\',
            'Karband\\ExternalAuthLaravel\\',
            'Karband\\ServiceRecipients',
            'Karband\\ServiceRequests',
            'Karband\\DynamicForms',
            'Karband\\Documents',
            'Karband\\Billing',
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
                    "karband/authentication-laravel must not import {$forbidden} in {$file->getFilename()}"
                );
            }
        }
    }
}
