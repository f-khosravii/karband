<?php

declare(strict_types=1);

namespace Intl\Directory\Tests;

use Intl\Directory\Exceptions\DirectoryValidationException;
use Intl\Directory\ValueObjects\ResolvedDirectoryActor;
use PHPUnit\Framework\TestCase;

final class ResolvedDirectoryActorTest extends TestCase
{
    public function test_organization_only_actor(): void
    {
        $actor = new ResolvedDirectoryActor(organizationId: 'org-agency-1');

        $this->assertSame('org-agency-1', $actor->organizationId);
        $this->assertNull($actor->userId);
        $this->assertTrue($actor->isOrganizationOnlyActor());
        $this->assertFalse($actor->isUserActor());
    }

    public function test_user_in_organization_actor(): void
    {
        $actor = new ResolvedDirectoryActor(organizationId: 'org-operator-1', userId: 'user-007');

        $this->assertSame('org-operator-1', $actor->organizationId);
        $this->assertSame('user-007', $actor->userId);
        $this->assertFalse($actor->isOrganizationOnlyActor());
        $this->assertTrue($actor->isUserActor());
    }

    public function test_empty_organization_id_throws(): void
    {
        $this->expectException(DirectoryValidationException::class);
        new ResolvedDirectoryActor('   ');
    }
}
