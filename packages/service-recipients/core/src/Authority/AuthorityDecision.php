<?php

declare(strict_types=1);
namespace Karband\ServiceRecipients\Authority;

enum AuthorityDecision: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Denied = 'denied';
}
