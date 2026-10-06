<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Bridge;

use Intl\Billing\Refund\RefundPolicy;
use Intl\Billing\ValueObjects\Money;
use Intl\BillingLaravel\Contracts\AcceptedQuoteResolverInterface;
use Intl\BillingLaravel\Contracts\PayableRequestGuard;
use Intl\BillingLaravel\Contracts\TrustedFinancialTermsProvider;
use Intl\BillingLaravel\Contracts\TrustedQuoteProvider;
use Intl\BillingLaravel\Contracts\TrustedRefundPolicyProvider;
use Intl\BillingLaravel\ValueObjects\FinancialTermsSnapshot;
use Intl\ServiceCatalog\Financial\PricingMode;
use Intl\ServiceCatalog\Financial\ServiceFinancialTerms;
use Intl\ServiceCatalogLaravel\Services\CatalogStorage;

/**
 * Reads each financial snapshot from ONE local authoritative catalog row.
 * Never auto-register: real PayableRequestGuard must be provided per host.
 * quote()/forPayment() exist for legacy interfaces; configure PaymentService
 * with TrustedFinancialTermsProvider for ATOMIC price/policy snapshot reads.
 */
final readonly class LocalCatalogFinancialTermsProvider implements
    TrustedFinancialTermsProvider, TrustedQuoteProvider, TrustedRefundPolicyProvider
{
    public function __construct(
        private CatalogStorage $catalog,
        private PayableRequestGuard $requests,
        private ?AcceptedQuoteResolverInterface $quoteResolver = null,
    ) {}

    public function snapshot(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): FinancialTermsSnapshot
    {
        if ($this->catalog->connection->getName() !== $this->requests->connectionName()) {
            throw new \RuntimeException('Financial policy and payable request must belong to the same local DB.');
        }
        $this->requests->assertPayable($serviceCode, $resourceType, $resourceId, $agencyId);
        $row = $this->catalog->connection->table('intl_catalog_services')
            ->where('code', $serviceCode)->where('status', 'active')->first();
        if ($row === null) {
            throw new \RuntimeException('No active local service found for trusted pricing.');
        }
        $settings = json_decode((string) $row->settings, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw new \UnexpectedValueException('Malformed local catalog settings.');
        }
        $terms = ServiceFinancialTerms::fromCatalogSettings($settings);
        $revision = (int) $row->revision;

        if ($terms->pricingMode === PricingMode::RequestQuote) {
            if ($this->quoteResolver === null) {
                throw new \RuntimeException('No accepted quote resolver configured for request_quote pricing mode.');
            }
            $quotePricing = $this->quoteResolver->resolveAcceptedQuote($serviceCode, $resourceType, $resourceId, $agencyId);
            return new FinancialTermsSnapshot(
                money: $quotePricing->money,
                refundPolicy: RefundPolicy::fromArray($terms->refundEvents),
                catalogRevision: $revision,
                pricingMode: PricingMode::RequestQuote,
                pricingRevision: $quotePricing->revision,
                refundPolicyRevision: $revision,
            );
        }

        if ($terms->pricingMode === PricingMode::NoCharge) {
            return new FinancialTermsSnapshot(
                money: Money::zero($terms->currency),
                refundPolicy: RefundPolicy::fromArray($terms->refundEvents),
                catalogRevision: $revision,
                pricingMode: PricingMode::NoCharge,
                pricingRevision: $revision,
                refundPolicyRevision: $revision,
            );
        }

        return new FinancialTermsSnapshot(
            money: new Money($terms->fixedPrice, $terms->currency),
            refundPolicy: RefundPolicy::fromArray($terms->refundEvents),
            catalogRevision: $revision,
            pricingMode: PricingMode::FixedCatalog,
            pricingRevision: $revision,
            refundPolicyRevision: $revision,
        );
    }

    public function quote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): Money
    {
        return $this->snapshot($serviceCode, $resourceType, $resourceId, $agencyId)->money;
    }

    public function forPayment(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): RefundPolicy
    {
        return $this->snapshot($serviceCode, $resourceType, $resourceId, $agencyId)->refundPolicy;
    }
}
