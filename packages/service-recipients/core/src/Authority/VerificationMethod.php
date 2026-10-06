<?php

declare(strict_types=1);
namespace Karband\ServiceRecipients\Authority;

enum VerificationMethod: string
{
    case Manual = 'manual';
    case External = 'external';
    case Hybrid = 'hybrid';
}
