<?php

declare(strict_types=1);

namespace Karband\ServiceRequests\ValueObjects;

enum QuoteStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return $this === self::Accepted;
    }

    public function isCurrent(): bool
    {
        return $this === self::Proposed || $this === self::Accepted;
    }
}
