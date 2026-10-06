<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Services;

use Intl\Billing\ValueObjects\PaymentStatus;

/** Backend-only trusted Workflow fact; never expose this raw lookup as an unauthenticated endpoint. */
final readonly class TrustedPaymentFacts
{
    public function __construct(private PaymentStorage $storage) {}

    public function isConfirmed(string $serviceCode, string $resourceType, string $resourceId): bool
    {
        return $this->storage->connection->table('intl_payment_intents')
            ->where('service_code', $serviceCode)->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)->where('status', PaymentStatus::Succeeded->value)->exists();
    }
}
