<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

use Karband\ServiceRequests\ValueObjects\RecipientReference;

/** Required host policy: check actual agency/recipient association using trusted local records. */
interface RecipientVerifier
{
    public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool;
}
