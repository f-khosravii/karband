<?php

declare(strict_types=1);

namespace Karband\Billing\ValueObjects;

final readonly class ConfirmPaymentCommand
{
    public function __construct(
        public string $operationKey,
        public string $paymentId,
        public string $centralPaymentId,
        public Money $amount,
    ) {}
}
