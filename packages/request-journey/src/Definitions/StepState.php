<?php

declare(strict_types=1);

namespace Intl\RequestJourney\Definitions;

final class StepState
{
    public const LOCKED = 'locked';
    public const AVAILABLE = 'available';
    public const CURRENT = 'current';
    public const COMPLETED = 'completed';
    public const SKIPPED = 'skipped';

    public const ALL = [
        self::LOCKED,
        self::AVAILABLE,
        self::CURRENT,
        self::COMPLETED,
        self::SKIPPED,
    ];

    public static function isValid(string $state): bool
    {
        return in_array($state, self::ALL, true);
    }
}
