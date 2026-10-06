<?php

declare(strict_types=1);

namespace Intl\Billing\ValueObjects;

final readonly class PreparePaymentCommand
{
    public function __construct(
        public string $operationKey,
        public string $paymentId,
        public string $serviceCode,
        public string $resourceType,
        public string $resourceId,
        public string $agencyId,
        public Money $amount,
        public int $revision = 0,
    ) {}
}
