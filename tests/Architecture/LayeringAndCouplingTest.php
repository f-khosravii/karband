<?php

declare(strict_types=1);

namespace Karband\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class LayeringAndCouplingTest extends TestCase
{
    public function test_recursive_discovery_finds_all_packages(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        self::assertCount(25, $packages, 'Must discover all 25 monorepo packages dynamically');
        
        foreach ($packages as $name => $info) {
            self::assertNotEmpty($info['name'], "Package at {$info['path']} must have a valid name");
            self::assertFileExists($info['composer_path']);
        }
    }

    public function test_core_packages_have_no_framework_dependencies(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        $forbiddenNamespaces = [
            'Illuminate\\',
            'Laravel\\',
            'Spatie\\Permission\\',
            'Spatie\\Activitylog\\',
            'Symfony\\Component\\HttpFoundation\\',
        ];

        $violations = [];

        foreach ($packages as $name => $info) {
            if (!$info['is_core']) {
                continue;
            }

            // Check composer require
            $require = $info['composer']['require'] ?? [];
            foreach (array_keys($require) as $dep) {
                if (str_starts_with($dep, 'illuminate/') || str_starts_with($dep, 'laravel/') || str_starts_with($dep, 'spatie/')) {
                    $violations[] = "Core package {$name} composer.json requires framework package {$dep}";
                }
            }

            // Check PHP source imports and references
            foreach ($info['php_files'] as $file) {
                $refs = ArchitectureTestHelper::extractImportsAndReferences($file);
                foreach ($refs as $ref) {
                    foreach ($forbiddenNamespaces as $forbidden) {
                        if (str_starts_with($ref['class'], $forbidden)) {
                            $violations[] = "Core package {$name} in {$file}:{$ref['line']} references framework class {$ref['class']}";
                        }
                    }
                }
            }
        }

        self::assertEmpty($violations, "Core packages must have no framework dependencies:\n" . implode("\n", $violations));
    }

    public function test_core_packages_have_no_adapter_imports(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        $violations = [];

        foreach ($packages as $name => $info) {
            if (!$info['is_core']) {
                continue;
            }

            foreach ($info['php_files'] as $file) {
                $refs = ArchitectureTestHelper::extractImportsAndReferences($file);
                foreach ($refs as $ref) {
                    if (str_contains($ref['class'], 'Laravel\\') || str_ends_with($ref['class'], 'Laravel')) {
                        $violations[] = "Core package {$name} in {$file}:{$ref['line']} references adapter class {$ref['class']}";
                    }
                }
            }
        }

        self::assertEmpty($violations, "Core packages must have no adapter dependencies:\n" . implode("\n", $violations));
    }

    public function test_packages_have_no_circular_dependencies(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        $graph = [];

        foreach ($packages as $name => $info) {
            $graph[$name] = [];
            foreach (array_keys($info['composer']['require'] ?? []) as $dep) {
                if (isset($packages[$dep])) {
                    $graph[$name][] = $dep;
                }
            }
        }

        $visited = [];
        $visiting = [];

        $hasCycle = function (string $pkg) use (&$hasCycle, &$visited, &$visiting, $graph): bool {
            if (isset($visiting[$pkg])) {
                return true;
            }
            if (isset($visited[$pkg])) {
                return false;
            }

            $visiting[$pkg] = true;

            foreach ($graph[$pkg] ?? [] as $dep) {
                if ($hasCycle($dep)) {
                    return true;
                }
            }

            unset($visiting[$pkg]);
            $visited[$pkg] = true;
            return false;
        };

        foreach (array_keys($graph) as $pkg) {
            self::assertFalse($hasCycle($pkg), "Circular dependency detected involving package: {$pkg}");
        }
    }

    public function test_no_forbidden_intl_identity(): void
    {
        $packages = ArchitectureTestHelper::getPackages();

        foreach ($packages as $name => $info) {
            self::assertFalse(
                str_starts_with($name, 'intl/'),
                "Package {$name} must use karband/ vendor instead of intl/"
            );
            
            if ($info['namespace'] !== null) {
                self::assertFalse(
                    str_starts_with($info['namespace'], 'Intl\\'),
                    "Package {$name} namespace {$info['namespace']} must start with Karband\\ instead of Intl\\"
                );
            }
        }
    }

    public function test_no_adapter_to_adapter_composer_dependencies(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        $violations = [];

        foreach ($packages as $pkgName => $info) {
            if (!$info['is_adapter']) {
                continue;
            }

            $requires = $info['composer']['require'] ?? [];
            foreach (array_keys($requires) as $dep) {
                if (isset($packages[$dep]) && $packages[$dep]['is_adapter']) {
                    $violations[] = "Adapter package {$pkgName} requires adapter package {$dep} in composer.json";
                }
            }
        }

        self::assertEmpty($violations, "Adapter packages must not require other adapter packages in composer.json:\n" . implode("\n", $violations));
    }

    public function test_no_adapter_to_adapter_source_references(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        $violations = [];

        foreach ($packages as $pkgName => $info) {
            if (!$info['is_adapter']) {
                continue;
            }

            foreach ($info['php_files'] as $file) {
                $refs = ArchitectureTestHelper::extractImportsAndReferences($file);
                foreach ($refs as $ref) {
                    foreach ($packages as $targetName => $targetInfo) {
                        if ($targetName === $pkgName || !$targetInfo['is_adapter'] || !$targetInfo['namespace']) {
                            continue;
                        }

                        $targetNs = rtrim($targetInfo['namespace'], '\\');
                        if (str_starts_with($ref['class'], $targetNs . '\\') || $ref['class'] === $targetNs) {
                            $violations[] = "Adapter package {$pkgName} in {$file}:{$ref['line']} references class {$ref['class']} from adapter {$targetName}";
                        }
                    }
                }
            }
        }

        self::assertEmpty($violations, "Adapter packages must not reference other adapter packages in source code:\n" . implode("\n", $violations));
    }

    public function test_no_foreign_table_queries(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        $tableMap = ArchitectureTestHelper::getCapabilityTableMap();
        $violations = [];

        foreach ($packages as $pkgName => $info) {
            $capName = str_replace(['karband/', '-laravel'], '', $pkgName);
            $ownedTables = $tableMap[$capName] ?? [];

            foreach ($info['php_files'] as $file) {
                $accesses = ArchitectureTestHelper::extractTableAccesses($file);
                foreach ($accesses as $access) {
                    $table = $access['table'];
                    $isOwned = false;
                    foreach ($ownedTables as $owned) {
                        if ($table === $owned || str_starts_with($table, $owned)) {
                            $isOwned = true;
                            break;
                        }
                    }

                    if (!$isOwned) {
                        $violations[] = "Package {$pkgName} in {$file}:{$access['line']} queries foreign table '{$table}' (owned by {$access['owner']})";
                    }
                }
            }
        }

        self::assertEmpty($violations, "Packages must not query foreign tables directly:\n" . implode("\n", $violations));
    }

    public function test_no_undeclared_cross_package_dependencies(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        $violations = [];

        foreach ($packages as $pkgName => $info) {
            $declared = array_keys($info['composer']['require'] ?? []);

            foreach ($info['php_files'] as $file) {
                $refs = ArchitectureTestHelper::extractImportsAndReferences($file);
                foreach ($refs as $ref) {
                    foreach ($packages as $targetName => $targetInfo) {
                        if ($targetName === $pkgName || !$targetInfo['namespace']) {
                            continue;
                        }

                        $targetNs = rtrim($targetInfo['namespace'], '\\');
                        if (str_starts_with($ref['class'], $targetNs . '\\') || $ref['class'] === $targetNs) {
                            if (!in_array($targetName, $declared, true)) {
                                $violations[] = "Package {$pkgName} in {$file}:{$ref['line']} imports {$ref['class']} from undeclared dependency {$targetName}";
                            }
                        }
                    }
                }
            }
        }

        self::assertEmpty($violations, "Cross-package imports must be declared in composer.json:\n" . implode("\n", $violations));
    }
}
