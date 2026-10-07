<?php

declare(strict_types=1);

namespace Karband\Authentication\Contracts;

use Karband\Authentication\Entities\AuthenticationAccount;
use Karband\Authentication\Enums\AuthenticationAccountStatus;
use Karband\Authentication\ValueObjects\AuthenticationAccountCredential;

/**
 * Consumer-owned port for Authentication Account persistence.
 */
interface AuthenticationStoragePortInterface
{
    /**
     * Find an authentication account by its stable ID.
     */
    public function findById(string $id): ?AuthenticationAccount;

    /**
     * Find an authentication account by its normalized login identifier.
     */
    public function findByLoginIdentifier(string $normalizedIdentifier): ?AuthenticationAccount;

    /**
     * Find an internal credential record for authentication verification.
     */
    public function findCredentialByLoginIdentifier(string $normalizedIdentifier): ?AuthenticationAccountCredential;

    /**
     * Persist a new authentication account along with its password hash.
     * Must fail with AuthenticationAccountConflictException on duplicate login identifier.
     */
    public function save(AuthenticationAccount $account, string $passwordHash): void;

    /**
     * Update status of an authentication account.
     */
    public function updateStatus(string $id, AuthenticationAccountStatus $status): void;

    /**
     * Update password hash of an authentication account.
     */
    public function updatePasswordHash(string $id, string $passwordHash): void;
}
