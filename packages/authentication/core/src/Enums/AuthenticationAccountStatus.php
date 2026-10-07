<?php

declare(strict_types=1);

namespace Karband\Authentication\Enums;

enum AuthenticationAccountStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isDisabled(): bool
    {
        return $this === self::Disabled;
    }
}
