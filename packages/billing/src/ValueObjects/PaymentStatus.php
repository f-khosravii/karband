<?php

declare(strict_types=1);

namespace Intl\Billing\ValueObjects;

enum PaymentStatus: string
{
    case Ready = 'ready';             // created locally; central request not attempted
    case Preparing = 'preparing';     // prepare operation in flight / uncertain
    case Prepared = 'prepared';       // central prepare / hold succeeded
    case Confirming = 'confirming';   // confirm operation in flight / uncertain
    case Unknown = 'unknown';         // outcome uncertain; MUST lookup / reconcile, never charge again
    case Pending = 'pending';         // central accepted request but not finalized (retained for backward compat)
    case Processing = 'processing';   // in flight (retained for backward compat)
    case Succeeded = 'succeeded';     // central confirm / capture succeeded
    case Failed = 'failed';           // ONLY provider-confirmed definitive NO-DEBIT failure
    case Cancelled = 'cancelled';     // prepared payment released / cancelled centrally

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed || $this === self::Cancelled;
    }

    public function isUncertain(): bool
    {
        return $this === self::Preparing || $this === self::Confirming || $this === self::Unknown || $this === self::Processing;
    }
}
