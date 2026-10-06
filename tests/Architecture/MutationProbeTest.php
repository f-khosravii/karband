<?php

declare(strict_types=1);

namespace Karband\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class MutationProbeTest extends TestCase
{
    public function test_probe_detects_core_framework_dependency(): void
    {
        $code = "<?php\nnamespace Karband\\Billing\\Engine;\nuse Illuminate\\Support\\Str;\nclass Dummy {}";
        $tmpFile = sys_get_temp_dir() . '/probe_core_framework_' . uniqid() . '.php';
        file_put_contents($tmpFile, $code);

        try {
            $imports = ArchitectureTestHelper::extractImports($tmpFile);
            $found = false;
            foreach ($imports as $imp) {
                if (str_starts_with($imp, 'Illuminate\\')) {
                    $found = true;
                    break;
                }
            }
            self::assertTrue($found, 'Mutation probe must detect core framework dependency');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_probe_detects_core_adapter_dependency(): void
    {
        $code = "<?php\nnamespace Karband\\Billing\\Engine;\nuse Karband\\WorkflowLaravel\\Services\\WorkflowRuntime;\nclass Dummy {}";
        $tmpFile = sys_get_temp_dir() . '/probe_core_adapter_' . uniqid() . '.php';
        file_put_contents($tmpFile, $code);

        try {
            $imports = ArchitectureTestHelper::extractImports($tmpFile);
            $found = false;
            foreach ($imports as $imp) {
                if (str_contains($imp, 'Laravel\\') || str_ends_with($imp, 'Laravel')) {
                    $found = true;
                    break;
                }
            }
            self::assertTrue($found, 'Mutation probe must detect core adapter dependency');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_probe_detects_foreign_table_query(): void
    {
        $code = "<?php\nnamespace Karband\\ServiceRequestsLaravel\\Services;\nclass Dummy { public function f(\$db) { return \$db->table('catalog_services')->first(); } }";
        $tmpFile = sys_get_temp_dir() . '/probe_foreign_table_' . uniqid() . '.php';
        file_put_contents($tmpFile, $code);

        try {
            $accesses = ArchitectureTestHelper::extractTableAccesses($tmpFile);
            self::assertCount(1, $accesses);
            self::assertSame('catalog_services', $accesses[0]['table']);
            self::assertSame('service-catalog', $accesses[0]['owner']);
            self::assertSame(3, $accesses[0]['line']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_probe_detects_adapter_to_adapter_import(): void
    {
        $code = "<?php\nnamespace Karband\\ServiceRequestsLaravel\\Services;\nuse Karband\\BillingLaravel\\Services\\PaymentService;\nclass Dummy {}";
        $tmpFile = sys_get_temp_dir() . '/probe_a2a_import_' . uniqid() . '.php';
        file_put_contents($tmpFile, $code);

        try {
            $refs = ArchitectureTestHelper::extractImportsAndReferences($tmpFile);
            $found = false;
            foreach ($refs as $ref) {
                if (str_starts_with($ref['class'], 'Karband\\BillingLaravel\\')) {
                    $found = true;
                    self::assertSame(3, $ref['line']);
                    break;
                }
            }
            self::assertTrue($found, 'Mutation probe must detect adapter-to-adapter import');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_probe_detects_group_use_and_fqcn_references(): void
    {
        $code = "<?php\nnamespace Karband\\Dummy;\nuse Karband\\WorkflowLaravel\\{Services\\WorkflowRuntime, ValueObjects\\ActionOutcome};\nclass Dummy { public function run(): \\Karband\\BillingLaravel\\Services\\PaymentService { return null; } }";
        $tmpFile = sys_get_temp_dir() . '/probe_group_use_' . uniqid() . '.php';
        file_put_contents($tmpFile, $code);

        try {
            $refs = ArchitectureTestHelper::extractImportsAndReferences($tmpFile);
            $classes = array_column($refs, 'class');
            self::assertContains('Karband\\WorkflowLaravel\\Services\\WorkflowRuntime', $classes);
            self::assertContains('Karband\\WorkflowLaravel\\ValueObjects\\ActionOutcome', $classes);
            self::assertContains('Karband\\BillingLaravel\\Services\\PaymentService', $classes);
        } finally {
            @unlink($tmpFile);
        }
    }
}
