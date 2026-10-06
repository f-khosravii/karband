<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

interface RequestPaymentPort
{
    public function createIntent(string $serviceCode, string $resourceType, string $resourceId, string $paymentIdempotencyKey): mixed;

    public function retryFailed(string $paymentId, string $retryKey): mixed;

    public function view(string $paymentId): mixed;

    public function assertNoSuccessfulPayment(string $requestId): void;

    public function assertNoUnresolvedPayment(string $requestId): void;

    public function assertNoPaymentsExist(string $requestId): void;

    public function connectionName(): ?string;
}
