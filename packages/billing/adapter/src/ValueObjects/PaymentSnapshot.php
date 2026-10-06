<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\ValueObjects;

use Karband\Billing\ValueObjects\Money;
use Karband\Billing\ValueObjects\PaymentStatus;

final readonly class PaymentSnapshot
{
    public function __construct(
        public string $id,
        public string $serviceCode,
        public string $resourceType,
        public string $resourceId,
        public string $agencyId,
        public Money $money,
        public PaymentStatus $status,
        public int $attempt,
        public int $revision,
        public ?string $centralReference,
        public ?string $centralPaymentId = null,
        public string $pricingSource = 'fixed_catalog',
        public ?int $capturedAmount = null,
        public ?int $centralFeeAmount = null,
        public ?int $centralTaxAmount = null,
        public ?int $centralTotalAmount = null,
        public ?string $prepareOperationKey = null,
        public ?string $confirmOperationKey = null,
    ) {}
}
