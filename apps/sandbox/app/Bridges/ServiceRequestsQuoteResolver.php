<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\Billing\ValueObjects\Money;
use Karband\BillingLaravel\Contracts\AcceptedQuoteResolverInterface;
use Karband\BillingLaravel\ValueObjects\QuotePricingResult;
use Karband\ServiceRequestsLaravel\Services\RequestQuoteService;
use RuntimeException;

/** Host composition bridge: resolves accepted quotes from Service Requests for Billing. */
final readonly class ServiceRequestsQuoteResolver implements AcceptedQuoteResolverInterface
{
    public function __construct(
        private RequestQuoteService $quoteService,
    ) {
    }

    public function resolveAcceptedQuote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): QuotePricingResult
    {
        $proposal = $this->quoteService->acceptedQuote($resourceId);
        if ($proposal === null) {
            throw new RuntimeException('No accepted quote found for request_quote service.');
        }

        return new QuotePricingResult(
            money: new Money($proposal->amount, $proposal->currency),
            revision: $proposal->revision,
        );
    }
}
