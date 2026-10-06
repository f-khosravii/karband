<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\Contracts;

use Karband\Billing\Refund\RefundPolicy;

/** Map the host's LOCAL authoritative service policy. Never trust a client-supplied policy. */
interface TrustedRefundPolicyProvider
{
    public function forPayment(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): RefundPolicy;
}
