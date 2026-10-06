<?php

declare(strict_types=1);

namespace Intl\Directory\Enums;

enum OrganizationRole: string
{
    case Operator = 'operator';
    case Provider = 'provider';
    case Agency = 'agency';
}
