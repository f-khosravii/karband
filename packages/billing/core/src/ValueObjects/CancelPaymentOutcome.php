<?php

declare(strict_types=1);

namespace Karband\Billing\ValueObjects;

final readonly class CancelPaymentOutcome
{
    public function __construct(
        public PaymentStatus $status,
        public string $centralPaymentId,
        public ?string $rawMessage = null,
    ) {}

    public static function cancelled(string $centralPaymentId): self
    {
        return new self(status: PaymentStatus::Cancelled, centralPaymentId: $centralPaymentId);
    }

    public static function failed(string $centralPaymentId, ?string $message = null): self
    {
        return new self(status: PaymentStatus::Failed, centralPaymentId: $centralPaymentId, rawMessage: $message);
    }

    public static function unknown(string $centralPaymentId, ?string $message = null): self
    {
        return new self(status: PaymentStatus::Unknown, centralPaymentId: $centralPaymentId, rawMessage: $message);
    }
}
