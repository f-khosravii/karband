<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\ValueObjects;

use Intl\Billing\ValueObjects\Money;

/** Value object containing accepted quote price and revision for snapshotting. */
final readonly class QuotePricingResult
{
    public function __construct(
        public Money $money,
        public int $revision,
    ) {
        if ($revision < 1) {
            throw new \InvalidArgumentException('Quote pricing revision must be at least 1.');
        }
    }
}
