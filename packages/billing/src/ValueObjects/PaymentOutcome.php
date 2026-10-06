<?php

declare(strict_types=1);

namespace Intl\Billing\ValueObjects;

/** Status MUST come from authenticated, validated central response, not caller input. */
final readonly class PaymentOutcome
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $providerReference = null,
        public ?int $feeAmount = null,
        public ?int $taxAmount = null,
        public ?int $totalAmount = null,
        public ?int $capturedAmount = null,
    ) {
        if ($providerReference !== null && (trim($providerReference) === '' || trim($providerReference) !== $providerReference || strlen($providerReference) > 191 || preg_match('/[\x00-\x1F\x7F]/', $providerReference))) {
            throw new \InvalidArgumentException('Invalid central payment reference.');
        }
        if (($status === PaymentStatus::Succeeded || $status === PaymentStatus::Prepared) && $providerReference === null) {
            throw new \InvalidArgumentException('A confirmed successful or prepared payment needs a central reference.');
        }
        if (!in_array($status, [PaymentStatus::Prepared, PaymentStatus::Succeeded, PaymentStatus::Failed, PaymentStatus::Pending, PaymentStatus::Cancelled, PaymentStatus::Unknown], true)) {
            throw new \InvalidArgumentException('Central gateway cannot return a local-only state.');
        }
    }

    public static function prepared(string $reference, ?int $fee = 0, ?int $tax = 0, ?int $total = null): self
    {
        return new self(PaymentStatus::Prepared, $reference, feeAmount: $fee, taxAmount: $tax, totalAmount: $total);
    }

    public static function succeeded(string $reference, ?int $capturedAmount = null): self
    {
        return new self(PaymentStatus::Succeeded, $reference, capturedAmount: $capturedAmount);
    }

    public static function cancelled(string $reference): self
    {
        return new self(PaymentStatus::Cancelled, $reference);
    }

    /** Only when provider GUARANTEES no debit occurred. Timeouts, 5xx and "not found" are UNKNOWN. */
    public static function failed(?string $reference = null): self { return new self(PaymentStatus::Failed, $reference); }
    public static function pending(?string $reference = null): self { return new self(PaymentStatus::Pending, $reference); }
    public static function unknown(): self { return new self(PaymentStatus::Unknown); }
}
