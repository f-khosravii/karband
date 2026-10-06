<?php

declare(strict_types=1);

namespace Karband\Billing\ValueObjects;

final readonly class PreparePaymentOutcome
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $centralPaymentId = null,
        public ?int $centralFeeAmount = null,
        public ?int $centralTaxAmount = null,
        public ?int $centralTotalAmount = null,
        public ?string $rawMessage = null,
    ) {}

    public static function prepared(
        string $centralPaymentId,
        ?int $feeAmount = 0,
        ?int $taxAmount = 0,
        ?int $totalAmount = null,
    ): self {
        return new self(
            status: PaymentStatus::Prepared,
            centralPaymentId: $centralPaymentId,
            centralFeeAmount: $feeAmount,
            centralTaxAmount: $taxAmount,
            centralTotalAmount: $totalAmount,
        );
    }

    public static function failed(?string $message = null): self
    {
        return new self(status: PaymentStatus::Failed, rawMessage: $message);
    }

    public static function unknown(?string $message = null): self
    {
        return new self(status: PaymentStatus::Unknown, rawMessage: $message);
    }
}
