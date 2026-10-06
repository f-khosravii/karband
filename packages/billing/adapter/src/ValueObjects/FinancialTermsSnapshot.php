<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\ValueObjects;

use Karband\Billing\Refund\RefundPolicy;
use Karband\Billing\ValueObjects\Money;
use Karband\ServiceCatalog\Financial\PricingMode;

/** A price AND refund policy read together from the SAME trusted catalog revision. */
final readonly class FinancialTermsSnapshot
{
    public function __construct(
        public Money $money,
        public RefundPolicy $refundPolicy,
        public int $catalogRevision,
        public PricingMode $pricingMode = PricingMode::FixedCatalog,
        public int $pricingRevision = 0,
        public int $refundPolicyRevision = 0,
    ) {
        if ($catalogRevision < 0) {
            throw new \InvalidArgumentException('Invalid catalog revision.');
        }
    }
}
