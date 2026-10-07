<?php

declare(strict_types=1);

namespace Karband\Directory\Tests;

use DateTimeImmutable;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryValidationException;
use Karband\Directory\ValueObjects\Membership;
use Karband\Directory\ValueObjects\OrganizationRole;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    public function test_valid_membership(): void
    {
        $now = new DateTimeImmutable('2026-01-01 10:00:00');
        $m = new Membership(
            id: 'mem-1',
            userId: 'user-1',
            organizationId: 'org-1',
            role: new OrganizationRole('owner'),
            status: DirectoryStatus::Active,
            createdAt: $now,
            endedAt: null,
        );

        $this->assertSame('mem-1', $m->id);
        $this->assertSame('user-1', $m->userId);
        $this->assertSame('org-1', $m->organizationId);
        $this->assertTrue($m->isActive());
        $this->assertSame('owner', $m->role->value);
        $this->assertSame($now, $m->createdAt);
        $this->assertNull($m->endedAt);
    }

    public function test_ended_membership_period(): void
    {
        $start = new DateTimeImmutable('2026-01-01 10:00:00');
        $end = new DateTimeImmutable('2026-06-01 18:00:00');

        $m = new Membership(
            id: 'mem-2',
            userId: 'user-1',
            organizationId: 'org-2',
            role: new OrganizationRole('consultant'),
            status: DirectoryStatus::Inactive,
            createdAt: $start,
            endedAt: $end,
        );

        $this->assertFalse($m->isActive());
        $this->assertSame('consultant', $m->role->value);
        $this->assertSame($start, $m->createdAt);
        $this->assertSame($end, $m->endedAt);
    }

    public function test_invalid_membership_fields(): void
    {
        $this->expectException(DirectoryValidationException::class);
        new Membership(
            id: ' ',
            userId: 'user-1',
            organizationId: 'org-1'
        );
    }
}
