<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients\Authority;

enum VerificationMethod: string
{
    case Manual = 'manual';
    case External = 'external';
    case Hybrid = 'hybrid';
}
