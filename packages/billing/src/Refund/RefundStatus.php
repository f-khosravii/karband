<?php

declare(strict_types=1);

namespace Intl\Billing\Refund;

enum RefundStatus: string
{
    case AwaitingApproval = 'awaiting_approval';
    case ManualPending = 'manual_pending';
    case Ready = 'ready';
    case Processing = 'processing';
    case Pending = 'pending';
    case Unknown = 'unknown';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Rejected], true);
    }
}
