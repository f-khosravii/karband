<?php

declare(strict_types=1);

namespace Karband\AuthenticationLaravel\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Karband\Authentication\Contracts\AuthenticationStoragePortInterface;
use Karband\Authentication\Entities\AuthenticationAccount;
use Karband\Authentication\Enums\AuthenticationAccountStatus;
use Karband\Authentication\Exceptions\AuthenticationAccountConflictException;
use Karband\Authentication\Exceptions\AuthenticationAccountNotFoundException;
use Karband\Authentication\ValueObjects\AuthenticationAccountCredential;

final class AuthenticationStorage implements AuthenticationStoragePortInterface
{
    private string $tableName;

    public function __construct(
        private readonly ConnectionInterface $connection,
        ?string $tableName = null
    ) {
        $this->tableName = $tableName ?? (string) config('authentication.table', 'authentication_accounts');
    }

    public function findById(string $id): ?AuthenticationAccount
    {
        $row = $this->connection->table($this->tableName)
            ->where('id', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateAccount((array) $row);
    }

    public function findByLoginIdentifier(string $normalizedIdentifier): ?AuthenticationAccount
    {
        $row = $this->connection->table($this->tableName)
            ->where('login_identifier', $normalizedIdentifier)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateAccount((array) $row);
    }

    public function findCredentialByLoginIdentifier(string $normalizedIdentifier): ?AuthenticationAccountCredential
    {
        $row = $this->connection->table($this->tableName)
            ->where('login_identifier', $normalizedIdentifier)
            ->first();

        if ($row === null) {
            return null;
        }

        $data = (array) $row;

        return new AuthenticationAccountCredential(
            accountId: (string) $data['id'],
            loginIdentifier: (string) $data['login_identifier'],
            passwordHash: (string) $data['password_hash'],
            status: AuthenticationAccountStatus::from((string) $data['status']),
        );
    }

    public function save(AuthenticationAccount $account, string $passwordHash): void
    {
        try {
            $this->connection->table($this->tableName)->insert([
                'id' => $account->id,
                'login_identifier' => $account->loginIdentifier,
                'password_hash' => $passwordHash,
                'status' => $account->status->value,
                'created_at' => $account->createdAt?->format('Y-m-d H:i:s') ?? date('Y-m-d H:i:s'),
                'updated_at' => $account->updatedAt?->format('Y-m-d H:i:s') ?? date('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new AuthenticationAccountConflictException(
                    "An authentication account with login identifier '{$account->loginIdentifier}' already exists.",
                    (int) $e->getCode(),
                    $e
                );
            }
            throw $e;
        }
    }

    public function updateStatus(string $id, AuthenticationAccountStatus $status): void
    {
        $affected = $this->connection->table($this->tableName)
            ->where('id', $id)
            ->update([
                'status' => $status->value,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($affected === 0) {
            $exists = $this->connection->table($this->tableName)->where('id', $id)->exists();
            if (!$exists) {
                throw new AuthenticationAccountNotFoundException("Authentication account '{$id}' not found.");
            }
        }
    }

    public function updatePasswordHash(string $id, string $passwordHash): void
    {
        $affected = $this->connection->table($this->tableName)
            ->where('id', $id)
            ->update([
                'password_hash' => $passwordHash,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($affected === 0) {
            $exists = $this->connection->table($this->tableName)->where('id', $id)->exists();
            if (!$exists) {
                throw new AuthenticationAccountNotFoundException("Authentication account '{$id}' not found.");
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateAccount(array $data): AuthenticationAccount
    {
        $createdAt = isset($data['created_at']) && is_string($data['created_at'])
            ? new \DateTimeImmutable($data['created_at'])
            : null;

        $updatedAt = isset($data['updated_at']) && is_string($data['updated_at'])
            ? new \DateTimeImmutable($data['updated_at'])
            : null;

        return new AuthenticationAccount(
            id: (string) $data['id'],
            loginIdentifier: (string) $data['login_identifier'],
            status: AuthenticationAccountStatus::from((string) $data['status']),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        return $sqlState === '23000'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'integrity constraint violation');
    }
}
