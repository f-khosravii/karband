<?php

declare(strict_types=1);

namespace Karband\Billing\Refund;

enum RefundInitiation: string
{
    case Automatic = 'automatic';
    case AdminApproval = 'admin_approval';
    case Manual = 'manual';
}
