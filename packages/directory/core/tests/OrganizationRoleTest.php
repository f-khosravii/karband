<?php

declare(strict_types=1);

namespace Karband\Directory\Tests;

use Karband\Directory\Exceptions\DirectoryValidationException;
use Karband\Directory\ValueObjects\OrganizationRole;
use PHPUnit\Framework\TestCase;

final class OrganizationRoleTest extends TestCase
{
    public function test_valid_role_instantiation(): void
    {
        $role = new OrganizationRole('member');
        $this->assertSame('member', $role->value);
        $this->assertSame('member', (string) $role);

        $custom = new OrganizationRole('Operations Lead');
        $this->assertSame('Operations Lead', $custom->value);
    }

    public function test_default_role(): void
    {
        $default = OrganizationRole::default();
        $this->assertSame('member', $default->value);
    }

    public function test_empty_role_throws_validation_exception(): void
    {
        $this->expectException(DirectoryValidationException::class);
        new OrganizationRole('   ');
    }

    public function test_overly_long_role_throws_validation_exception(): void
    {
        $this->expectException(DirectoryValidationException::class);
        new OrganizationRole(str_repeat('a', 65));
    }

    public function test_equality(): void
    {
        $role1 = new OrganizationRole('finance');
        $role2 = new OrganizationRole('FINANCE');
        $role3 = new OrganizationRole('legal');

        $this->assertTrue($role1->equals($role2));
        $this->assertFalse($role1->equals($role3));
    }
}
