<?php

declare(strict_types=1);

namespace Karband\Directory\Tests;

use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryValidationException;
use Karband\Directory\ValueObjects\Membership;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    public function test_valid_membership(): void
    {
        $m = new Membership(
            id: 'mem-1',
            userId: 'user-1',
            organizationId: 'org-1',
            status: DirectoryStatus::Active
        );

        $this->assertSame('mem-1', $m->id);
        $this->assertSame('user-1', $m->userId);
        $this->assertSame('org-1', $m->organizationId);
        $this->assertTrue($m->isActive());
    }

    public function test_inactive_membership(): void
    {
        $m = new Membership(
            id: 'mem-2',
            userId: 'user-1',
            organizationId: 'org-2',
            status: DirectoryStatus::Inactive
        );

        $this->assertFalse($m->isActive());
    }

    public function test_invalid_membership_fields(): void
    {
        $this->expectException(DirectoryValidationException::class);
        new Membership(' ', 'user-1', 'org-1', DirectoryStatus::Active);
    }
}
