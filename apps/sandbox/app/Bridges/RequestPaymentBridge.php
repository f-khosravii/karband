<?php

declare(strict_types=1);

namespace App\Bridges;

use Illuminate\Contracts\Container\Container;
use Karband\BillingLaravel\Services\PaymentService;
use Karband\BillingLaravel\Services\PaymentStorage;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Contracts\RequestPaymentPort;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;

final readonly class RequestPaymentBridge implements RequestPaymentPort
{
    public function __construct(
        private PaymentStorage $storage,
        private Container $container,
    ) {}

    private function paymentService(): PaymentService
    {
        return $this->container->make(PaymentService::class);
    }

    public function createIntent(string $serviceCode, string $resourceType, string $resourceId, string $paymentIdempotencyKey): mixed
    {
        return $this->paymentService()->create($serviceCode, $resourceType, $resourceId, $paymentIdempotencyKey);
    }

    public function retryFailed(string $paymentId, string $retryKey): mixed
    {
        return $this->paymentService()->retryFailed($paymentId, $retryKey);
    }

    public function view(string $paymentId): mixed
    {
        return $this->paymentService()->view($paymentId);
    }

    public function assertNoSuccessfulPayment(string $requestId): void
    {
        if ($this->storage->connection->table('payment_intents')
            ->where('resource_type', RequestResource::TYPE)->where('resource_id', $requestId)
            ->where('status', 'succeeded')->exists()) {
            throw new RequestConflict('Paid draft requires a separate trusted cancellation/refund policy.');
        }
    }

    public function assertNoUnresolvedPayment(string $requestId): void
    {
        if ($this->storage->connection->table('payment_intents')
            ->where('resource_type', RequestResource::TYPE)->where('resource_id', $requestId)
            ->whereIn('status', ['processing', 'unknown', 'pending'])->exists()) {
            throw new RequestConflict('An unresolved payment must be reconciled before final cancellation.');
        }
    }

    public function assertNoPaymentsExist(string $requestId): void
    {
        if ($this->storage->connection->table('payment_intents')
            ->where('resource_type', RequestResource::TYPE)->where('resource_id', $requestId)
            ->exists()) {
            throw new RequestConflict('Resolve or remove the local payment intent before deleting a draft.');
        }
    }

    public function connectionName(): ?string
    {
        return $this->storage->connection->getName();
    }
}
