<?php

declare(strict_types=1);

namespace Karband\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CircularDependencyTest extends TestCase
{
    public function test_packages_have_no_circular_dependencies(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        $graph = [];

        foreach ($packages as $name => $info) {
            $graph[$name] = [];
            foreach (array_keys($info['composer']['require'] ?? []) as $dependency) {
                if (str_starts_with($dependency, 'karband/')) {
                    $graph[$name][] = $dependency;
                }
            }
        }

        $visited = [];
        $visiting = [];

        $hasCycle = function (string $package) use (
            &$hasCycle,
            &$visited,
            &$visiting,
            $graph
        ): bool {
            if (isset($visiting[$package])) {
                return true;
            }

            if (isset($visited[$package])) {
                return false;
            }

            $visiting[$package] = true;

            foreach ($graph[$package] ?? [] as $dependency) {
                if ($hasCycle($dependency)) {
                    return true;
                }
            }

            unset($visiting[$package]);
            $visited[$package] = true;

            return false;
        };

        foreach (array_keys($graph) as $package) {
            self::assertFalse(
                $hasCycle($package),
                "Circular dependency detected involving {$package}"
            );
        }
    }
}