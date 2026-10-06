<?php

declare(strict_types=1);

namespace Intl\Directory\Enums;

enum DirectoryStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
