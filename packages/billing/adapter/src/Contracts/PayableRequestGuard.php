<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\Contracts;

/**
 * Mandatory host binding, not a permission stub: verify authenticated agency
 * owns request, this service matches, and the request is payable NOW under
 * its PINNED workflow definition. Must share the LOCAL catalog DB connection.
 */
interface PayableRequestGuard
{
    public function connectionName(): ?string;
    public function assertPayable(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): void;
}
