<?php

declare(strict_types=1);

namespace Intl\Contracts\Tests;

use Intl\Contracts\Contracts\HealthCheckInterface;
use PHPUnit\Framework\TestCase;

final class HealthCheckInterfaceTest extends TestCase
{
    public function test_contract_is_available(): void
    {
        self::assertTrue(
            interface_exists(HealthCheckInterface::class)
        );
    }
}