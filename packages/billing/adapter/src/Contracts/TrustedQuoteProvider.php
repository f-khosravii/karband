<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\Contracts;

use Karband\Billing\ValueObjects\Money;

/** Host MUST verify service/resource/agency, payable workflow state and authoritative price. No HTTP price. */
interface TrustedQuoteProvider
{
    public function quote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): Money;
}
