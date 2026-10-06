<?php

declare(strict_types=1);

namespace Intl\Contracts\Contracts;

interface HealthCheckInterface
{
    public function check(): bool;
}