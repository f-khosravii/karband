<?php

declare(strict_types=1);

namespace Karband\Authorization;

use RuntimeException;

final class AuthorizationDenied extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'access_denied',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Authorization denied: ' . $reasonCode, $code, $previous);
    }
}
