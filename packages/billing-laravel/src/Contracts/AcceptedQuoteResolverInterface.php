<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Contracts;

use Intl\BillingLaravel\ValueObjects\QuotePricingResult;

/** Contract for resolving an accepted quote's financial terms for request_quote pricing mode. */
interface AcceptedQuoteResolverInterface
{
    public function resolveAcceptedQuote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): QuotePricingResult;
}
