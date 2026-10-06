<?php

namespace Tests\Feature;

use Intl\Contracts\Contracts\HealthCheckInterface;
use Tests\TestCase;

class ContractsIntegrationTest extends TestCase
{
    public function test_shared_contract_is_available(): void
    {
        $this->assertTrue(
            interface_exists(HealthCheckInterface::class)
        );
    }
}