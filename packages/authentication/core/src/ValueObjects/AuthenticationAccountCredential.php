<?php

declare(strict_types=1);

namespace Karband\Authentication\ValueObjects;

use Karband\Authentication\Enums\AuthenticationAccountStatus;
use Karband\Authentication\Exceptions\AuthenticationValidationException;

/**
 * Internal persistence/authentication credential record holding the hashed credential.
 * Not exposed on public entity lookups or returned from application services.
 */
final class AuthenticationAccountCredential
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $loginIdentifier,
        public readonly string $passwordHash,
        public readonly AuthenticationAccountStatus $status,
    ) {
        if (trim($this->accountId) === '') {
            throw new AuthenticationValidationException('Account ID cannot be empty.');
        }

        if (trim($this->loginIdentifier) === '') {
            throw new AuthenticationValidationException('Login identifier cannot be empty.');
        }

        if (trim($this->passwordHash) === '') {
            throw new AuthenticationValidationException('Password hash cannot be empty.');
        }
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isDisabled(): bool
    {
        return $this->status->isDisabled();
    }
}
