<?php

declare(strict_types=1);

namespace Karband\Billing\Refund;

use Karband\Billing\ValueObjects\Money;

/** This command describes a FULL refund, with NO wallet or balance semantics. */
final readonly class RefundCommand
{
    public function __construct(
        public string $requestKey,
        public string $refundId,
        public string $paymentId,
        public string $originalPaymentReference,
        public Money $money,
    ) {
        foreach ([$requestKey, $refundId, $paymentId, $originalPaymentReference] as $value) {
            if ($value === '' || trim($value) !== $value || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException('Invalid full-refund command identifier.');
            }
        }
    }
}
