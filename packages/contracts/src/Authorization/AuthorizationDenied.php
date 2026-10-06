<?php

declare(strict_types=1);

namespace Intl\Contracts\Authorization;

final class AuthorizationDenied extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode = 'access_denied')
    {
        parent::__construct('Authorization denied: ' . $reasonCode);
    }
}
