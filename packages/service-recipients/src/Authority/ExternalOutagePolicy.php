<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients\Authority;

enum ExternalOutagePolicy: string
{
    case Wait = 'wait';
    case AuthorizedManualFallback = 'authorized_manual_fallback';
}
