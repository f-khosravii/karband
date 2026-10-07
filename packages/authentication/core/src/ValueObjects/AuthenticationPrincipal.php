<?php

declare(strict_types=1);

namespace Karband\Authentication\ValueObjects;

use Karband\Authentication\Exceptions\AuthenticationValidationException;

final class AuthenticationPrincipal
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $loginIdentifier,
    ) {
        if (trim($this->accountId) === '') {
            throw new AuthenticationValidationException('Account ID cannot be empty.');
        }

        if (trim($this->loginIdentifier) === '') {
            throw new AuthenticationValidationException('Login identifier cannot be empty.');
        }
    }
}
