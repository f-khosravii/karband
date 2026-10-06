<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients\Authority;

enum AuthorityDecision: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Denied = 'denied';
}
