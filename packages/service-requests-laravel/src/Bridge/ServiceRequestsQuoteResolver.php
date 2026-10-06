<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Bridge;

use Intl\Billing\ValueObjects\Money;
use Intl\BillingLaravel\Contracts\AcceptedQuoteResolverInterface;
use Intl\BillingLaravel\ValueObjects\QuotePricingResult;
use Intl\ServiceRequestsLaravel\Services\RequestQuoteService;

/**
 * Service Requests bridge implementation resolving an accepted quote for Billing.
 * Implements Billing's AcceptedQuoteResolverInterface without coupling Billing to Service Requests internals.
 */
final readonly class ServiceRequestsQuoteResolver implements AcceptedQuoteResolverInterface
{
    public function __construct(
        private RequestQuoteService $quoteService,
    ) {}

    public function resolveAcceptedQuote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): QuotePricingResult
    {
        $proposal = $this->quoteService->acceptedQuote($resourceId);
        if ($proposal === null) {
            throw new \RuntimeException('No accepted quote found for request_quote service.');
        }

        return new QuotePricingResult(
            money: new Money($proposal->amount, $proposal->currency),
            revision: $proposal->revision,
        );
    }
}
