<?php

declare(strict_types=1);

namespace Intl\ExternalAuth\ValueObjects;

enum ExternalSessionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
