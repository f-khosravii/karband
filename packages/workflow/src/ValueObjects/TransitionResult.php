<?php

declare(strict_types=1);

namespace Intl\Workflow\ValueObjects;

use Intl\Workflow\Support\Identifier;

/** Evaluation only: it does not mutate the request or record audit history. */
final readonly class TransitionResult
{
    private function __construct(
        public bool $allowed,
        public ?string $nextState,
        public ?string $reasonCode,
        public ?string $failedGuard,
        public ?string $guardReasonCode,
    ) {
    }

    public static function permit(string $nextState): self
    {
        Identifier::assert($nextState, 'Next state');
        return new self(true, $nextState, null, null, null);
    }

    public static function reject(
        string $reasonCode,
        ?string $failedGuard = null,
        ?string $guardReasonCode = null,
    ): self {
        Identifier::assert($reasonCode, 'Result reason code');
        if ($failedGuard !== null) {
            Identifier::assert($failedGuard, 'Failed guard');
        }
        if ($guardReasonCode !== null) {
            Identifier::assert($guardReasonCode, 'Guard reason code');
        }
        return new self(false, null, $reasonCode, $failedGuard, $guardReasonCode);
    }
}
