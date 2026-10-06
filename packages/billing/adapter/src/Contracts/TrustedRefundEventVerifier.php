<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\Contracts;

/** Check a real, trusted business event (e.g. authorized cancellation), not a submitted event label. */
interface TrustedRefundEventVerifier
{
    public function verify(
        string $eventCode, string $eventId, string $serviceCode, string $resourceType,
        string $resourceId, string $agencyId
    ): bool;
}
