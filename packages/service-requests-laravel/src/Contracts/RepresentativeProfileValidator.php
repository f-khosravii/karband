<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Contracts;

/** Host validates the service-specific schema of an ephemeral legal representative. */
interface RepresentativeProfileValidator
{
    /** @param array<string,mixed> $profile */
    public function assertValid(string $serviceCode, array $profile): void;
}
