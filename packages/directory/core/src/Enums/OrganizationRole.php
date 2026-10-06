<?php

declare(strict_types=1);

namespace Karband\Directory\Enums;

enum OrganizationRole: string
{
    case Operator = 'operator';
    case Provider = 'provider';
    case Agency = 'agency';
}
