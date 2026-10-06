<?php

declare(strict_types=1);

namespace Intl\Billing\Refund;

final readonly class RefundStateMachine
{
    public function afterGatewayResult(RefundStatus $before, RefundOutcome $result): RefundStatus
    {
        if ($before->isFinal()) { return $before; }
        if (!in_array($before, [RefundStatus::Processing, RefundStatus::Unknown, RefundStatus::Pending], true)) {
            throw new \DomainException('No provider refund was dispatched.');
        }
        // An ambiguous lookup must never erase a previously confirmed pending state.
        if ($before === RefundStatus::Pending && $result->status === RefundStatus::Unknown) {
            return $before;
        }
        return $result->status;
    }
}
