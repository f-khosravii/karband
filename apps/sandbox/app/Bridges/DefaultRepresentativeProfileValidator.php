<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator;

/**
 * Host validator: validates service-specific representative profile structure.
 */
final readonly class DefaultRepresentativeProfileValidator implements RepresentativeProfileValidator
{
    public function assertValid(string $serviceCode, array $profile): void
    {
        // Default host pass-through; specific services can enforce custom schema
    }
}
