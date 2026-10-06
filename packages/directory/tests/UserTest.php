<?php

declare(strict_types=1);

namespace Intl\Directory\Tests;

use Intl\Directory\Enums\DirectoryStatus;
use Intl\Directory\Exceptions\DirectoryValidationException;
use Intl\Directory\ValueObjects\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function test_valid_user_instantiation(): void
    {
        $user = new User(
            id: 'user-001',
            displayName: 'Ahmad Manager',
            status: DirectoryStatus::Active,
            email: 'ahmad@example.com',
            phone: '+989120000000'
        );

        $this->assertSame('user-001', $user->id);
        $this->assertSame('Ahmad Manager', $user->displayName);
        $this->assertTrue($user->isActive());
        $this->assertSame('ahmad@example.com', $user->email);
        $this->assertSame('+989120000000', $user->phone);
    }

    public function test_inactive_user(): void
    {
        $user = new User(
            id: 'user-002',
            displayName: 'Deactivated Person',
            status: DirectoryStatus::Inactive
        );

        $this->assertFalse($user->isActive());
        $this->assertNull($user->email);
        $this->assertNull($user->phone);
    }

    public function test_empty_display_name_throws_exception(): void
    {
        $this->expectException(DirectoryValidationException::class);
        new User(
            id: 'user-003',
            displayName: '   ',
            status: DirectoryStatus::Active
        );
    }
}
