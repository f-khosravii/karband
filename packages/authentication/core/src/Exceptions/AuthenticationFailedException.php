<?php

declare(strict_types=1);

namespace Karband\Authentication\Exceptions;

final class AuthenticationFailedException extends AuthenticationException
{
    public function __construct(string $message = 'Invalid credentials.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
