<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients\Authority;

enum VerificationEvidence: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Unavailable = 'unavailable';
    case Missing = 'missing';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
