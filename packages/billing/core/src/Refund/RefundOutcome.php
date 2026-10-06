<?php

declare(strict_types=1);

namespace Karband\Billing\Refund;

/** FAILED is permitted only on a trustworthy definitive NO-REFUND response. */
final readonly class RefundOutcome
{
    private function __construct(public RefundStatus $status, public ?string $providerReference = null)
    {
        if (!in_array($status, [RefundStatus::Succeeded, RefundStatus::Failed, RefundStatus::Pending, RefundStatus::Unknown], true)) {
            throw new \InvalidArgumentException('Gateway returned a non-provider refund state.');
        }
        if ($providerReference !== null && (trim($providerReference) !== $providerReference || $providerReference === ''
            || strlen($providerReference) > 191 || preg_match('/[\x00-\x1F\x7F]/', $providerReference))) {
            throw new \InvalidArgumentException('Invalid refund reference.');
        }
        if ($status === RefundStatus::Succeeded && $providerReference === null) {
            throw new \InvalidArgumentException('Confirmed refund requires a provider reference.');
        }
    }

    public static function succeeded(string $reference): self { return new self(RefundStatus::Succeeded, $reference); }
    public static function failed(?string $reference = null): self { return new self(RefundStatus::Failed, $reference); }
    public static function pending(?string $reference = null): self { return new self(RefundStatus::Pending, $reference); }
    public static function unknown(): self { return new self(RefundStatus::Unknown); }
}
