<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

use Karband\ServiceRequests\Policies\RequestPolicy;

interface RequestCatalogPort
{
    public function getPolicy(string $serviceCode): RequestPolicy;

    public function assertServiceActive(string $serviceCode): void;

    public function assertQuotePricingSupported(string $serviceCode): void;

    public function connectionName(): ?string;
}
