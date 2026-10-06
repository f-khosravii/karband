<?php

declare(strict_types=1);

namespace Intl\Billing\ValueObjects;

/** What an INTL microservice says to the CENTRAL PAYMENT API; no wallet semantics. */
final readonly class PaymentCommand
{
    public function __construct(
        public string $requestKey,
        public string $paymentId,
        public string $serviceCode,
        public string $resourceType,
        public string $resourceId,
        public string $agencyId,
        public Money $money,
    ) {
        foreach ([$requestKey, $paymentId, $serviceCode, $resourceType, $resourceId, $agencyId] as $value) {
            if ($value === '' || trim($value) !== $value || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException('Payment command identifiers must be nonempty and at most 255 bytes.');
            }
        }
    }
}
