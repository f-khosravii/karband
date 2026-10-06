<?php

declare(strict_types=1);

namespace Karband\Billing\ValueObjects;

final readonly class ConfirmPaymentOutcome
{
    public function __construct(
        public PaymentStatus $status,
        public string $centralPaymentId,
        public ?int $capturedAmount = null,
        public ?string $rawMessage = null,
    ) {}

    public static function succeeded(string $centralPaymentId, int $capturedAmount): self
    {
        return new self(
            status: PaymentStatus::Succeeded,
            centralPaymentId: $centralPaymentId,
            capturedAmount: $capturedAmount,
        );
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
