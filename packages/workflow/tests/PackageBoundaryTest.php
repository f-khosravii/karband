<?php

declare(strict_types=1);

namespace Intl\Workflow\Tests;

use PHPUnit\Framework\TestCase;

final class PackageBoundaryTest extends TestCase
{
    public function test_package_does_not_depend_on_laravel_or_other_intl_packages(): void
    {
        $config = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(['php' => '^8.4'], $config['require']);
        self::assertSame(['Intl\\Workflow\\' => 'src/'], $config['autoload']['psr-4']);
    }

    public function test_example_definition_loads_without_laravel_or_database(): void
    {
        $definition = \Intl\Workflow\Definitions\WorkflowDefinition::fromJson(
            (string) file_get_contents(dirname(__DIR__) . '/examples/ncr-like-v1.json'),
        );
        self::assertSame('ncr.request', $definition->id);
        self::assertSame(1, $definition->version);
        self::assertNotNull($definition->findTransition('submitted', 'require_correction'));
    }
}
