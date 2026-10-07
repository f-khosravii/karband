<?php

declare(strict_types=1);

namespace Karband\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PackageDependencyTest extends TestCase
{
    public function test_audit_and_authorization_have_no_framework_dependencies(): void
    {
        $packages = ArchitectureTestHelper::getPackages();

        foreach (['karband/audit', 'karband/authorization'] as $pkg) {
            self::assertArrayHasKey($pkg, $packages);
            $composer = $packages[$pkg]['composer'];
            foreach (array_keys($composer['require'] ?? []) as $dependency) {
                self::assertFalse(
                    str_starts_with($dependency, 'illuminate/')
                    || str_starts_with($dependency, 'laravel/')
                    || str_starts_with($dependency, 'spatie/'),
                    "{$pkg} must not depend on {$dependency}"
                );
            }
        }
    }

    public function test_pure_domain_packages_have_no_framework_dependencies(): void
    {
        $packages = ArchitectureTestHelper::getPackages();

        foreach ($packages as $name => $info) {
            if (!$info['is_core']) {
                continue;
            }
            $composer = $info['composer'];
            foreach (array_keys($composer['require'] ?? []) as $dep) {
                self::assertFalse(
                    str_starts_with($dep, 'illuminate/')
                    || str_starts_with($dep, 'laravel/')
                    || str_starts_with($dep, 'spatie/laravel-'),
                    "Pure package {$name} must not depend on framework package {$dep}"
                );
            }
        }
    }

    public function test_forbidden_cross_package_couplings(): void
    {
        $packages = ArchitectureTestHelper::getPackages();

        // 1. Billing must not depend on Service Requests (Quote)
        $billing = $packages['karband/billing']['composer'];
        self::assertArrayNotHasKey('karband/service-requests', $billing['require'] ?? []);
        self::assertArrayNotHasKey('karband/service-requests-laravel', $billing['require'] ?? []);

        // 2. Documents pure package must not depend on Workflow or Authorization Laravel
        $documents = $packages['karband/documents']['composer'];
        self::assertArrayNotHasKey('karband/workflow', $documents['require'] ?? []);
        self::assertArrayNotHasKey('karband/workflow-laravel', $documents['require'] ?? []);
        self::assertArrayNotHasKey('karband/authorization-laravel', $documents['require'] ?? []);

        // 3. Service Requests pure package must not depend on Dynamic Forms Laravel
        $requests = $packages['karband/service-requests']['composer'];
        self::assertArrayNotHasKey('karband/dynamic-forms-laravel', $requests['require'] ?? []);

        // 4. External Auth pure package must not depend on Authorization Laravel
        $extAuth = $packages['karband/external-auth']['composer'];
        self::assertArrayNotHasKey('karband/authorization-laravel', $extAuth['require'] ?? []);

        // 5. Request Journey pure package must not depend on Laravel packages
        $journey = $packages['karband/request-journey']['composer'];
        foreach (array_keys($journey['require'] ?? []) as $dep) {
            self::assertFalse(
                str_ends_with($dep, '-laravel'),
                "Request Journey pure package must not depend on {$dep}"
            );
        }

        // 6. Directory pure package must not depend on External Auth or Authorization Laravel
        $directory = $packages['karband/directory']['composer'];
        self::assertArrayNotHasKey('karband/external-auth', $directory['require'] ?? []);
        self::assertArrayNotHasKey('karband/authorization-laravel', $directory['require'] ?? []);
        self::assertArrayNotHasKey('karband/service-recipients', $directory['require'] ?? []);
    }

    public function test_all_packages_have_valid_composer_files(): void
    {
        $packages = ArchitectureTestHelper::getPackages();
        self::assertCount(26, $packages);

        foreach ($packages as $name => $info) {
            $composer = $info['composer'];
            self::assertArrayHasKey('name', $composer);
            self::assertArrayHasKey('autoload', $composer);
        }
    }
}