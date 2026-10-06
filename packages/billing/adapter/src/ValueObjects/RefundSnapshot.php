<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\ValueObjects;

use Karband\Billing\Refund\RefundInitiation;
use Karband\Billing\Refund\RefundStatus;
use Karband\Billing\ValueObjects\Money;

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
