<?php

declare(strict_types=1);

namespace Karband\Authentication\Tests;

use Karband\Authentication\Contracts\AuthenticationAuditPortInterface;
use Karband\Authentication\Contracts\AuthenticationStoragePortInterface;
use Karband\Authentication\Contracts\PasswordHasherPortInterface;
use Karband\Authentication\Entities\AuthenticationAccount;
use Karband\Authentication\Enums\AuthenticationAccountStatus;
use Karband\Authentication\Exceptions\AuthenticationAccountConflictException;
use Karband\Authentication\Exceptions\AuthenticationAccountNotFoundException;
use Karband\Authentication\Exceptions\AuthenticationFailedException;
use Karband\Authentication\Exceptions\AuthenticationValidationException;
use Karband\Authentication\Services\AuthenticationService;
use Karband\Authentication\ValueObjects\AuthenticationAccountCredential;
use PHPUnit\Framework\TestCase;

final class AuthenticationServiceTest extends TestCase
{
    private InMemoryAuthenticationStorage $storage;
    private FakePasswordHasher $hasher;
    private FakeAuthenticationAudit $audit;
    private AuthenticationService $service;

    protected function setUp(): void
    {
        $this->storage = new InMemoryAuthenticationStorage();
        $this->hasher = new FakePasswordHasher();
        $this->audit = new FakeAuthenticationAudit();
        $this->service = new AuthenticationService($this->storage, $this->hasher, $this->audit);
    }

    public function test_account_creation_succeeds_and_hashes_password_without_exposing_hash_on_returned_account(): void
    {
        $account = $this->service->createAccount('Admin@Example.com', 'secret123');

        self::assertSame('admin@example.com', $account->loginIdentifier);
        self::assertTrue($account->isActive());
        self::assertNotEmpty($account->id);

        // Prove returned entity has no callable or readable password hash
        $reflection = new \ReflectionClass($account);
        self::assertFalse($reflection->hasProperty('passwordHash'));
        self::assertFalse($reflection->hasMethod('exportPasswordHash'));

        self::assertCount(1, $this->audit->events);
        self::assertSame('authentication.account.created', $this->audit->events[0]['code']);
        self::assertSame('admin@example.com', $this->audit->events[0]['metadata']['login_identifier']);
        // Verify password never appears in audit
        self::assertArrayNotHasKey('password', $this->audit->events[0]['metadata']);
        self::assertArrayNotHasKey('password_hash', $this->audit->events[0]['metadata']);
    }

    public function test_account_creation_rejects_empty_password(): void
    {
        $this->expectException(AuthenticationValidationException::class);
        $this->service->createAccount('user@example.com', '   ');
    }

    public function test_account_creation_rejects_duplicate_normalized_identifier(): void
    {
        $this->service->createAccount('user@example.com', 'secret123');

        $this->expectException(AuthenticationAccountConflictException::class);
        $this->service->createAccount('USER@EXAMPLE.COM', 'anothersecret');
    }

    public function test_authenticate_succeeds_with_valid_credentials_and_executes_verifier_without_hash_generation(): void
    {
        $this->service->createAccount('user@example.com', 'secret123');

        // Reset counts before authenticate
        $this->hasher->hashCalls = 0;
        $this->hasher->verifyCalls = 0;

        $principal = $this->service->authenticate('User@Example.COM', 'secret123');

        self::assertSame('user@example.com', $principal->loginIdentifier);
        self::assertNotEmpty($principal->accountId);

        // Verification executed exactly once, hash generation executed 0 times during authentication
        self::assertSame(1, $this->hasher->verifyCalls);
        self::assertSame(0, $this->hasher->hashCalls);
    }

    public function test_authenticate_fails_with_wrong_password_and_executes_verifier(): void
    {
        $this->service->createAccount('user@example.com', 'secret123');

        $this->hasher->hashCalls = 0;
        $this->hasher->verifyCalls = 0;

        try {
            $this->service->authenticate('user@example.com', 'wrongpassword');
            self::fail('Expected AuthenticationFailedException was not thrown');
        } catch (AuthenticationFailedException) {
            self::assertSame(1, $this->hasher->verifyCalls);
            self::assertSame(0, $this->hasher->hashCalls);
        }
    }

    public function test_authenticate_fails_when_account_does_not_exist_and_executes_dummy_verifier_without_generation(): void
    {
        $this->hasher->hashCalls = 0;
        $this->hasher->verifyCalls = 0;

        try {
            $this->service->authenticate('nonexistent@example.com', 'secret123');
            self::fail('Expected AuthenticationFailedException was not thrown');
        } catch (AuthenticationFailedException) {
            // Proves constant-work enumeration resistance: hasher verify was invoked against dummy hash
            self::assertSame(1, $this->hasher->verifyCalls);
            // Proves dummy hash is not dynamically generated during missing-account authentication
            self::assertSame(0, $this->hasher->hashCalls);
        }
    }

    public function test_disabled_account_cannot_authenticate_and_executes_verifier_without_generation(): void
    {
        $account = $this->service->createAccount('user@example.com', 'secret123');
        $this->service->disableAccount($account->id);

        $this->hasher->hashCalls = 0;
        $this->hasher->verifyCalls = 0;

        try {
            $this->service->authenticate('user@example.com', 'secret123');
            self::fail('Expected AuthenticationFailedException was not thrown');
        } catch (AuthenticationFailedException) {
            // Proves verifier was called to prevent status timing side-channels
            self::assertSame(1, $this->hasher->verifyCalls);
            self::assertSame(0, $this->hasher->hashCalls);
        }
    }

    public function test_invalid_identifier_format_executes_verifier_without_generation(): void
    {
        $this->hasher->hashCalls = 0;
        $this->hasher->verifyCalls = 0;

        try {
            $this->service->authenticate('   ', 'secret123');
            self::fail('Expected AuthenticationFailedException was not thrown');
        } catch (AuthenticationFailedException) {
            self::assertSame(1, $this->hasher->verifyCalls);
            self::assertSame(0, $this->hasher->hashCalls);
        }
    }

    public function test_enable_account_allows_authentication_again(): void
    {
        $account = $this->service->createAccount('user@example.com', 'secret123');
        $this->service->disableAccount($account->id);

        $this->service->enableAccount($account->id);

        $principal = $this->service->authenticate('user@example.com', 'secret123');
        self::assertSame($account->id, $principal->accountId);
    }

    public function test_replace_password_invalidates_old_and_accepts_new(): void
    {
        $account = $this->service->createAccount('user@example.com', 'oldpassword');

        $this->service->replacePassword($account->id, 'newpassword');

        // Old password fails
        try {
            $this->service->authenticate('user@example.com', 'oldpassword');
            self::fail('Expected AuthenticationFailedException was not thrown');
        } catch (AuthenticationFailedException) {
            // Expected
        }

        // New password succeeds
        $principal = $this->service->authenticate('user@example.com', 'newpassword');
        self::assertSame($account->id, $principal->accountId);

        // Audit event recorded
        $eventCodes = array_column($this->audit->events, 'code');
        self::assertContains('authentication.password.replaced', $eventCodes);
    }

    public function test_find_account_by_id_and_login_identifier_returns_entity_without_hash(): void
    {
        $created = $this->service->createAccount('lookup@example.com', 'secret123');

        $byId = $this->service->findAccountById($created->id);
        self::assertNotNull($byId);
        self::assertSame('lookup@example.com', $byId->loginIdentifier);

        $byIdentifier = $this->service->findAccountByLoginIdentifier('Lookup@Example.com');
        self::assertNotNull($byIdentifier);
        self::assertSame($created->id, $byIdentifier->id);

        $reflection = new \ReflectionClass($byId);
        self::assertFalse($reflection->hasProperty('passwordHash'));
        self::assertFalse($reflection->hasMethod('exportPasswordHash'));
    }

    public function test_operations_on_nonexistent_account_throw_not_found(): void
    {
        $this->expectException(AuthenticationAccountNotFoundException::class);
        $this->service->disableAccount('nonexistent-id');
    }
}

final class InMemoryAuthenticationStorage implements AuthenticationStoragePortInterface
{
    /** @var array<string, AuthenticationAccount> */
    private array $accountsById = [];

    /** @var array<string, string> */
    private array $idByIdentifier = [];

    /** @var array<string, string> */
    private array $hashesById = [];

    public function findById(string $id): ?AuthenticationAccount
    {
        return $this->accountsById[$id] ?? null;
    }

    public function findByLoginIdentifier(string $normalizedIdentifier): ?AuthenticationAccount
    {
        $id = $this->idByIdentifier[$normalizedIdentifier] ?? null;
        if ($id === null) {
            return null;
        }

        return $this->accountsById[$id] ?? null;
    }

    public function findCredentialByLoginIdentifier(string $normalizedIdentifier): ?AuthenticationAccountCredential
    {
        $id = $this->idByIdentifier[$normalizedIdentifier] ?? null;
        if ($id === null) {
            return null;
        }

        $account = $this->accountsById[$id] ?? null;
        $hash = $this->hashesById[$id] ?? null;

        if ($account === null || $hash === null) {
            return null;
        }

        return new AuthenticationAccountCredential(
            accountId: $account->id,
            loginIdentifier: $account->loginIdentifier,
            passwordHash: $hash,
            status: $account->status,
        );
    }

    public function save(AuthenticationAccount $account, string $passwordHash): void
    {
        if (isset($this->idByIdentifier[$account->loginIdentifier])) {
            throw new AuthenticationAccountConflictException("Account '{$account->loginIdentifier}' already exists.");
        }

        $this->accountsById[$account->id] = $account;
        $this->idByIdentifier[$account->loginIdentifier] = $account->id;
        $this->hashesById[$account->id] = $passwordHash;
    }

    public function updateStatus(string $id, AuthenticationAccountStatus $status): void
    {
        if (!isset($this->accountsById[$id])) {
            throw new AuthenticationAccountNotFoundException();
        }
        $this->accountsById[$id] = $this->accountsById[$id]->withStatus($status);
    }

    public function updatePasswordHash(string $id, string $passwordHash): void
    {
        if (!isset($this->accountsById[$id])) {
            throw new AuthenticationAccountNotFoundException();
        }
        $this->hashesById[$id] = $passwordHash;
    }
}

final class FakePasswordHasher implements PasswordHasherPortInterface
{
    public int $hashCalls = 0;
    public int $verifyCalls = 0;
    private string $dummyHash = 'dummy_hash_payload';

    public function hash(string $plainText): string
    {
        $this->hashCalls++;
        return 'hashed_' . $plainText;
    }

    public function verify(string $plainText, string $hashedValue): bool
    {
        $this->verifyCalls++;
        return $hashedValue === 'hashed_' . $plainText;
    }

    public function dummyHash(): string
    {
        return $this->dummyHash;
    }
}

final class FakeAuthenticationAudit implements AuthenticationAuditPortInterface
{
    /** @var list<array{code: string, subjectType: string, subjectId: string, metadata: array}> */
    public array $events = [];

    public function record(string $code, string $subjectType, string $subjectId, array $metadata = []): void
    {
        $this->events[] = [
            'code' => $code,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'metadata' => $metadata,
        ];
    }
}
