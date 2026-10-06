<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\ValueObjects;

use Intl\Billing\Refund\RefundInitiation;
use Intl\Billing\Refund\RefundStatus;
use Intl\Billing\ValueObjects\Money;

final readonly class RefundSnapshot
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public string $eventCode,
        public RefundInitiation $mode,
        public RefundStatus $status,
        public Money $money,
        public ?string $centralReference,
    ) {}
}
