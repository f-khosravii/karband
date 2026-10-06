<?php

declare(strict_types=1);

namespace Intl\Platform\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PackageDependencyTest extends TestCase
{
    private string $packagesPath;

    protected function setUp(): void
    {
        $this->packagesPath = dirname(__DIR__, 2) . '/packages';
    }

    public function test_contracts_has_no_framework_dependencies(): void
    {
        $composer = $this->readComposer('contracts');

        foreach (array_keys($composer['require'] ?? []) as $dependency) {
            self::assertFalse(
                str_starts_with($dependency, 'illuminate/')
                || str_starts_with($dependency, 'laravel/')
                || str_starts_with($dependency, 'intl/'),
                "Contracts must not depend on {$dependency}"
            );
        }
    }

    public function test_pure_domain_packages_have_no_framework_dependencies(): void
    {
        $purePackages = [
            'billing',
            'contracts',
            'directory',
            'documents',
            'dynamic-forms',
            'external-auth',
            'request-journey',
            'service-catalog',
            'service-recipients',
            'service-requests',
            'workflow',
        ];

        foreach ($purePackages as $pkg) {
            $composer = $this->readComposer($pkg);
            foreach (array_keys($composer['require'] ?? []) as $dep) {
                self::assertFalse(
                    str_starts_with($dep, 'illuminate/')
                    || str_starts_with($dep, 'laravel/')
                    || str_starts_with($dep, 'spatie/laravel-'),
                    "Pure package {$pkg} must not depend on framework package {$dep}"
                );
            }
        }
    }

    public function test_forbidden_cross_package_couplings(): void
    {
        // 1. Billing must not depend on Service Requests (Quote)
        $billing = $this->readComposer('billing');
        self::assertArrayNotHasKey('intl/service-requests', $billing['require'] ?? []);
        self::assertArrayNotHasKey('intl/service-requests-laravel', $billing['require'] ?? []);

        // 2. Documents pure package must not depend on Workflow or Authorization Laravel
        $documents = $this->readComposer('documents');
        self::assertArrayNotHasKey('intl/workflow', $documents['require'] ?? []);
        self::assertArrayNotHasKey('intl/workflow-laravel', $documents['require'] ?? []);
        self::assertArrayNotHasKey('intl/authorization-laravel', $documents['require'] ?? []);

        // 3. Service Requests pure package must not depend on Dynamic Forms Laravel
        $requests = $this->readComposer('service-requests');
        self::assertArrayNotHasKey('intl/dynamic-forms-laravel', $requests['require'] ?? []);

        // 4. External Auth pure package must not depend on Authorization Laravel
        $extAuth = $this->readComposer('external-auth');
        self::assertArrayNotHasKey('intl/authorization-laravel', $extAuth['require'] ?? []);

        // 5. Request Journey pure package must not depend on Laravel packages
        $journey = $this->readComposer('request-journey');
        foreach (array_keys($journey['require'] ?? []) as $dep) {
            self::assertFalse(
                str_ends_with($dep, '-laravel'),
                "Request Journey pure package must not depend on {$dep}"
            );
        }

        // 6. Directory pure package must not depend on External Auth or Authorization Laravel
        $directory = $this->readComposer('directory');
        self::assertArrayNotHasKey('intl/external-auth', $directory['require'] ?? []);
        self::assertArrayNotHasKey('intl/authorization-laravel', $directory['require'] ?? []);
        self::assertArrayNotHasKey('intl/service-recipients', $directory['require'] ?? []);
    }

    public function test_all_packages_have_valid_composer_files(): void
    {
        foreach ($this->packageNames() as $package) {
            $composer = $this->readComposer($package);

            self::assertArrayHasKey('name', $composer);
            self::assertArrayHasKey('autoload', $composer);
        }
    }

    private function packageNames(): array
    {
        return array_values(array_filter(
            scandir($this->packagesPath),
            fn (string $name): bool =>
                $name !== '.'
                && $name !== '..'
                && is_dir($this->packagesPath . '/' . $name)
        ));
    }

    private function readComposer(string $package): array
    {
        $path = $this->packagesPath . '/' . $package . '/composer.json';

        self::assertFileExists($path);

        return json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}