<?php

declare(strict_types=1);

namespace Intl\RequestJourney\Definitions;

final class NavigationMode
{
    public const LINEAR = 'linear';
    public const FREE = 'free';

    public const ALL = [
        self::LINEAR,
        self::FREE,
    ];

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::ALL, true);
    }
}
