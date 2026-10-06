<?php

declare(strict_types=1);

namespace Karband\Billing\Engine;

use Karband\Billing\ValueObjects\PaymentOutcome;
use Karband\Billing\ValueObjects\PaymentStatus;

final readonly class PaymentStateMachine
{
    /** Never downgrade final success, definitive cancellation, or definitive failure. */
    public function afterGatewayResult(PaymentStatus $current, PaymentOutcome $outcome): PaymentStatus
    {
        if ($current->isFinal()) {
            return $current;
        }
        if ($current === PaymentStatus::Ready) {
            throw new \DomainException('Central result is invalid before the first dispatch or preparation.');
        }
        if (($current === PaymentStatus::Pending || $current === PaymentStatus::Prepared) && $outcome->status === PaymentStatus::Unknown) {
            return $current; // ambiguous lookup cannot erase confirmed prepared/pending state
        }
        return $outcome->status;
    }

    public function assertValidTransition(PaymentStatus $from, PaymentStatus $to): void
    {
        if ($from->isFinal()) {
            throw new \DomainException("Cannot transition from terminal state {$from->value} to {$to->value}.");
        }

        if ($to === PaymentStatus::Confirming && $from !== PaymentStatus::Prepared && $from !== PaymentStatus::Confirming && $from !== PaymentStatus::Unknown) {
            throw new \DomainException("Cannot start confirmation from state {$from->value}; payment must be prepared first.");
        }

        if ($from === PaymentStatus::Cancelled && $to === PaymentStatus::Succeeded) {
            throw new \DomainException("Cannot confirm a cancelled payment.");
        }

        if ($from === PaymentStatus::Succeeded && $to === PaymentStatus::Cancelled) {
            throw new \DomainException("Cannot cancel a succeeded payment.");
        }
    }

    public function canReconcile(PaymentStatus $current): bool
    {
        return in_array($current, [
            PaymentStatus::Preparing,
            PaymentStatus::Prepared,
            PaymentStatus::Confirming,
            PaymentStatus::Processing,
            PaymentStatus::Pending,
            PaymentStatus::Unknown,
        ], true);
    }
}
