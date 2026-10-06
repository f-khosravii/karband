<?php

declare(strict_types=1);

namespace Karband\DynamicFormsLaravel\Contracts;

interface ResponseEditGate
{
    /** @param array<string,mixed> $proposed */
    public function assertResponseAllowed(string $serviceCode, string $resourceType, string $resourceId,
        array $proposed): void;
}
