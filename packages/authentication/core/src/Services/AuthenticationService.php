<?php

declare(strict_types=1);

namespace Karband\Authentication\Services;

use Karband\Authentication\Contracts\AuthenticationAuditPortInterface;
use Karband\Authentication\Contracts\AuthenticationStoragePortInterface;
use Karband\Authentication\Contracts\PasswordHasherPortInterface;
use Karband\Authentication\Entities\AuthenticationAccount;
use Karband\Authentication\Enums\AuthenticationAccountStatus;
use Karband\Authentication\Exceptions\AuthenticationAccountNotFoundException;
use Karband\Authentication\Exceptions\AuthenticationFailedException;
use Karband\Authentication\Exceptions\AuthenticationValidationException;
use Karband\Authentication\ValueObjects\AuthenticationPrincipal;
use Karband\Authentication\ValueObjects\LoginIdentifier;

final class AuthenticationService
{
    public function __construct(
        private readonly AuthenticationStoragePortInterface $storage,
        private readonly PasswordHasherPortInterface $passwordHasher,
        private readonly ?AuthenticationAuditPortInterface $audit = null,
    ) {
    }

    /**
     * Create a new authentication account with hashed password.
     */
    public function createAccount(
        string $loginIdentifier,
        string $plainTextPassword,
        AuthenticationAccountStatus $status = AuthenticationAccountStatus::Active,
        ?string $accountId = null
    ): AuthenticationAccount {
        $identifier = LoginIdentifier::fromString($loginIdentifier);

        if (trim($plainTextPassword) === '') {
            throw new AuthenticationValidationException('Password cannot be empty.');
        }

        $id = $accountId ?? $this->generateUuid();
        $passwordHash = $this->passwordHasher->hash($plainTextPassword);
        $now = new \DateTimeImmutable();

        $account = new AuthenticationAccount(
            id: $id,
            loginIdentifier: (string) $identifier,
            status: $status,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->storage->save($account, $passwordHash);

        $this->audit?->record(
            code: 'authentication.account.created',
            subjectType: 'authentication_account',
            subjectId: $id,
            metadata: [
                'login_identifier' => (string) $identifier,
                'status' => $status->value,
            ]
        );

        return $account;
    }

    /**
     * Authenticate credentials and return AuthenticationPrincipal.
     * Enforces anti-enumeration semantics with timing safety: missing account, disabled account,
     * invalid identifier format, and wrong password all execute verifier work against valid hashes and throw generic AuthenticationFailedException.
     */
    public function authenticate(string $loginIdentifier, string $plainTextPassword): AuthenticationPrincipal
    {
        try {
            $identifier = LoginIdentifier::fromString($loginIdentifier);
        } catch (AuthenticationValidationException) {
            $this->passwordHasher->verify($plainTextPassword, $this->passwordHasher->dummyHash());
            throw new AuthenticationFailedException();
        }

        if (trim($plainTextPassword) === '') {
            $this->passwordHasher->verify($plainTextPassword, $this->passwordHasher->dummyHash());
            throw new AuthenticationFailedException();
        }

        $credential = $this->storage->findCredentialByLoginIdentifier((string) $identifier);

        if ($credential === null) {
            // Equivalent verifier work to prevent timing side-channels
            $this->passwordHasher->verify($plainTextPassword, $this->passwordHasher->dummyHash());
            throw new AuthenticationFailedException();
        }

        if ($credential->isDisabled()) {
            // Equivalent verifier work to prevent status timing side-channels
            $this->passwordHasher->verify($plainTextPassword, $credential->passwordHash);
            throw new AuthenticationFailedException();
        }

        if (!$this->passwordHasher->verify($plainTextPassword, $credential->passwordHash)) {
            throw new AuthenticationFailedException();
        }

        $this->audit?->record(
            code: 'authentication.authenticated',
            subjectType: 'authentication_account',
            subjectId: $credential->accountId,
            metadata: [
                'login_identifier' => $credential->loginIdentifier,
            ]
        );

        return new AuthenticationPrincipal(
            accountId: $credential->accountId,
            loginIdentifier: $credential->loginIdentifier,
        );
    }

    /**
     * Disable an authentication account.
     */
    public function disableAccount(string $accountId): void
    {
        $account = $this->storage->findById($accountId);
        if ($account === null) {
            throw new AuthenticationAccountNotFoundException("Authentication account '{$accountId}' not found.");
        }

        $this->storage->updateStatus($accountId, AuthenticationAccountStatus::Disabled);

        $this->audit?->record(
            code: 'authentication.account.disabled',
            subjectType: 'authentication_account',
            subjectId: $accountId,
            metadata: [
                'login_identifier' => $account->loginIdentifier,
            ]
        );
    }

    /**
     * Enable an authentication account.
     */
    public function enableAccount(string $accountId): void
    {
        $account = $this->storage->findById($accountId);
        if ($account === null) {
            throw new AuthenticationAccountNotFoundException("Authentication account '{$accountId}' not found.");
        }

        $this->storage->updateStatus($accountId, AuthenticationAccountStatus::Active);

        $this->audit?->record(
            code: 'authentication.account.enabled',
            subjectType: 'authentication_account',
            subjectId: $accountId,
            metadata: [
                'login_identifier' => $account->loginIdentifier,
            ]
        );
    }

    /**
     * Replace/set account password with a new hashed password (operator/admin bootstrap or replacement).
     */
    public function replacePassword(string $accountId, string $newPlainTextPassword): void
    {
        if (trim($newPlainTextPassword) === '') {
            throw new AuthenticationValidationException('New password cannot be empty.');
        }

        $account = $this->storage->findById($accountId);
        if ($account === null) {
            throw new AuthenticationAccountNotFoundException("Authentication account '{$accountId}' not found.");
        }

        $newHash = $this->passwordHasher->hash($newPlainTextPassword);
        $this->storage->updatePasswordHash($accountId, $newHash);

        $this->audit?->record(
            code: 'authentication.password.replaced',
            subjectType: 'authentication_account',
            subjectId: $accountId,
            metadata: [
                'login_identifier' => $account->loginIdentifier,
            ]
        );
    }

    /**
     * Find an authentication account by its ID.
     */
    public function findAccountById(string $accountId): ?AuthenticationAccount
    {
        return $this->storage->findById($accountId);
    }

    /**
     * Find an authentication account by its login identifier.
     */
    public function findAccountByLoginIdentifier(string $loginIdentifier): ?AuthenticationAccount
    {
        try {
            $identifier = LoginIdentifier::fromString($loginIdentifier);
        } catch (AuthenticationValidationException) {
            return null;
        }

        return $this->storage->findByLoginIdentifier((string) $identifier);
    }

    /**
     * Generate an RFC 4122 v4 UUID in pure PHP without external dependencies.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
