<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Contracts;

use Intl\BillingLaravel\ValueObjects\FinancialTermsSnapshot;

/**
 * Read authoritative amount AND refund policy as one validated snapshot.
 * Must fail closed on non-owned, missing, inactive or non-payable resources.
 */
interface TrustedFinancialTermsProvider
{
    public function snapshot(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): FinancialTermsSnapshot;
}
