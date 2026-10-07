<?php

declare(strict_types=1);

namespace Karband\Authentication\Tests;

use Karband\Authentication\Entities\AuthenticationAccount;
use Karband\Authentication\Enums\AuthenticationAccountStatus;
use Karband\Authentication\Exceptions\AuthenticationValidationException;
use PHPUnit\Framework\TestCase;

final class AuthenticationAccountTest extends TestCase
{
    public function test_account_creation_and_status_helpers(): void
    {
        $account = new AuthenticationAccount(
            id: 'acc-123',
            loginIdentifier: 'user@example.com',
            status: AuthenticationAccountStatus::Active,
        );

        self::assertSame('acc-123', $account->id);
        self::assertSame('user@example.com', $account->loginIdentifier);
        self::assertTrue($account->isActive());
        self::assertFalse($account->isDisabled());

        $disabled = $account->withStatus(AuthenticationAccountStatus::Disabled);
        self::assertTrue($disabled->isDisabled());
        self::assertFalse($disabled->isActive());
        self::assertSame('acc-123', $disabled->id);
    }

    public function test_account_contains_no_password_hash_property_or_method(): void
    {
        $account = new AuthenticationAccount(
            id: 'acc-123',
            loginIdentifier: 'user@example.com',
            status: AuthenticationAccountStatus::Active,
        );

        $reflection = new \ReflectionClass($account);

        // Prove no property named passwordHash or similar exists
        self::assertFalse($reflection->hasProperty('passwordHash'));
        self::assertFalse($reflection->hasProperty('password_hash'));
        self::assertFalse($reflection->hasProperty('password'));

        // Prove no export or getter method exists for credential material
        self::assertFalse($reflection->hasMethod('exportPasswordHash'));
        self::assertFalse($reflection->hasMethod('getPasswordHash'));
        self::assertFalse($reflection->hasMethod('passwordHash'));

        // JSON serialization strictly does not leak password hash
        $serialized = json_encode($account);
        self::assertIsString($serialized);
        self::assertStringNotContainsString('password', $serialized);
        self::assertStringNotContainsString('hash', $serialized);

        // Debug info does not leak password hash
        $debug = $account->__debugInfo();
        self::assertArrayNotHasKey('passwordHash', $debug);
        self::assertArrayNotHasKey('password_hash', $debug);
        self::assertArrayNotHasKey('password', $debug);
    }

    public function test_account_validation_on_empty_fields(): void
    {
        $this->expectException(AuthenticationValidationException::class);
        new AuthenticationAccount(
            id: '   ',
            loginIdentifier: 'user@example.com',
            status: AuthenticationAccountStatus::Active,
        );
    }
}
