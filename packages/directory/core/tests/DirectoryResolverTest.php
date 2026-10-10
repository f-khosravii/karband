<?php

declare(strict_types=1);

namespace Karband\Directory\Tests;

use DateTimeImmutable;
use Karband\Directory\Contracts\DirectoryStoragePortInterface;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryActorResolutionException;
use Karband\Directory\Services\DirectoryResolver;
use Karband\Directory\ValueObjects\Membership;
use Karband\Directory\ValueObjects\Organization;
use Karband\Directory\ValueObjects\OrganizationRole;
use Karband\Directory\ValueObjects\ResolvedDirectoryActor;
use Karband\Directory\ValueObjects\User;
use PHPUnit\Framework\TestCase;

final class DirectoryResolverTest extends TestCase
{
    private InMemoryDirectoryStorage $storage;
    private DirectoryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new InMemoryDirectoryStorage();
        $this->resolver = new DirectoryResolver($this->storage);
    }

    public function test_1_unknown_source_reference_fails_closed(): void
    {
        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('Unknown source reference');

        $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-missing');
    }

    public function test_2_active_user_with_zero_memberships_returns_empty_array(): void
    {
        $this->storage->saveUser(new User('usr-1', 'User One', DirectoryStatus::Active));
        $this->storage->saveExternalReference('auth', 'account', 'acc-1', 'user', null, 'usr-1');

        $actors = $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-1');

        $this->assertSame([], $actors);
    }

    public function test_3_active_user_with_one_active_membership_returns_one_resolved_actor(): void
    {
        $this->storage->saveOrganization(new Organization('org-1', 'Org One', DirectoryStatus::Active));
        $this->storage->saveUser(new User('usr-1', 'User One', DirectoryStatus::Active));
        $this->storage->saveMembership(new Membership(
            id: 'mem-1',
            userId: 'usr-1',
            organizationId: 'org-1',
            role: new OrganizationRole('operator'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveExternalReference('auth', 'account', 'acc-1', 'user', null, 'usr-1');

        $actors = $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-1');

        $this->assertCount(1, $actors);
        $this->assertSame('org-1', $actors[0]->organizationId);
        $this->assertSame('usr-1', $actors[0]->userId);
    }

    public function test_4_active_user_with_multiple_active_memberships_returns_all_valid_actors(): void
    {
        $this->storage->saveOrganization(new Organization('org-1', 'Org One', DirectoryStatus::Active));
        $this->storage->saveOrganization(new Organization('org-2', 'Org Two', DirectoryStatus::Active));
        $this->storage->saveUser(new User('usr-1', 'User One', DirectoryStatus::Active));

        $this->storage->saveMembership(new Membership(
            id: 'mem-1',
            userId: 'usr-1',
            organizationId: 'org-1',
            role: new OrganizationRole('member'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveMembership(new Membership(
            id: 'mem-2',
            userId: 'usr-1',
            organizationId: 'org-2',
            role: new OrganizationRole('lead'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveExternalReference('auth', 'account', 'acc-1', 'user', null, 'usr-1');

        $actors = $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-1');

        $this->assertCount(2, $actors);
        $this->assertSame('org-1', $actors[0]->organizationId);
        $this->assertSame('usr-1', $actors[0]->userId);
        $this->assertSame('org-2', $actors[1]->organizationId);
        $this->assertSame('usr-1', $actors[1]->userId);
    }

    public function test_5_and_6_ended_and_inactive_memberships_are_excluded(): void
    {
        $this->storage->saveOrganization(new Organization('org-active', 'Active Org', DirectoryStatus::Active));
        $this->storage->saveOrganization(new Organization('org-ended', 'Ended Org', DirectoryStatus::Active));
        $this->storage->saveOrganization(new Organization('org-inactive', 'Inactive Org', DirectoryStatus::Active));
        $this->storage->saveUser(new User('usr-1', 'User One', DirectoryStatus::Active));

        $this->storage->saveMembership(new Membership(
            id: 'mem-active',
            userId: 'usr-1',
            organizationId: 'org-active',
            role: new OrganizationRole('member'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveMembership(new Membership(
            id: 'mem-ended',
            userId: 'usr-1',
            organizationId: 'org-ended',
            role: new OrganizationRole('member'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable('-1 month'),
            endedAt: new DateTimeImmutable('-1 day')
        ));
        $this->storage->saveMembership(new Membership(
            id: 'mem-inactive',
            userId: 'usr-1',
            organizationId: 'org-inactive',
            role: new OrganizationRole('member'),
            status: DirectoryStatus::Inactive,
            createdAt: new DateTimeImmutable('-2 months')
        ));
        $this->storage->saveExternalReference('auth', 'account', 'acc-1', 'user', null, 'usr-1');

        $actors = $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-1');

        $this->assertCount(1, $actors);
        $this->assertSame('org-active', $actors[0]->organizationId);
    }

    public function test_7_inactive_organization_is_excluded_from_candidate_actors(): void
    {
        $this->storage->saveOrganization(new Organization('org-act', 'Active Org', DirectoryStatus::Active));
        $this->storage->saveOrganization(new Organization('org-inact', 'Inactive Org', DirectoryStatus::Inactive));
        $this->storage->saveUser(new User('usr-1', 'User One', DirectoryStatus::Active));

        $this->storage->saveMembership(new Membership(
            id: 'mem-1',
            userId: 'usr-1',
            organizationId: 'org-act',
            role: new OrganizationRole('member'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveMembership(new Membership(
            id: 'mem-2',
            userId: 'usr-1',
            organizationId: 'org-inact',
            role: new OrganizationRole('member'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveExternalReference('auth', 'account', 'acc-1', 'user', null, 'usr-1');

        $actors = $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-1');

        $this->assertCount(1, $actors);
        $this->assertSame('org-act', $actors[0]->organizationId);
    }

    public function test_8_archived_or_inactive_user_fails_closed(): void
    {
        $this->storage->saveOrganization(new Organization('org-1', 'Org One', DirectoryStatus::Active));
        $this->storage->saveUser(new User('usr-inactive', 'Inactive User', DirectoryStatus::Inactive));
        $this->storage->saveMembership(new Membership(
            id: 'mem-1',
            userId: 'usr-inactive',
            organizationId: 'org-1',
            role: new OrganizationRole('member'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveExternalReference('auth', 'account', 'acc-inactive', 'user', null, 'usr-inactive');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');

        $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-inactive');
    }

    public function test_9_duplicate_historical_membership_periods_do_not_duplicate_active_org_context(): void
    {
        $this->storage->saveOrganization(new Organization('org-1', 'Org One', DirectoryStatus::Active));
        $this->storage->saveUser(new User('usr-1', 'User One', DirectoryStatus::Active));

        // Past ended period for org-1
        $this->storage->saveMembership(new Membership(
            id: 'mem-p1',
            userId: 'usr-1',
            organizationId: 'org-1',
            role: new OrganizationRole('junior'),
            status: DirectoryStatus::Inactive,
            createdAt: new DateTimeImmutable('-1 year'),
            endedAt: new DateTimeImmutable('-6 months')
        ));
        // Current active period for same org-1
        $this->storage->saveMembership(new Membership(
            id: 'mem-p2',
            userId: 'usr-1',
            organizationId: 'org-1',
            role: new OrganizationRole('senior'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable('-1 month')
        ));
        $this->storage->saveExternalReference('auth', 'account', 'acc-1', 'user', null, 'usr-1');

        $actors = $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-1');

        $this->assertCount(1, $actors);
        $this->assertSame('org-1', $actors[0]->organizationId);
    }

    public function test_10_organization_target_source_reference_returns_one_organization_actor(): void
    {
        $this->storage->saveOrganization(new Organization('org-agency', 'Agency Org', DirectoryStatus::Active));
        $this->storage->saveExternalReference('epishkhan', 'organization', 'AG-100', 'organization', 'org-agency', null);

        $actors = $this->resolver->listAvailableActorsBySourceReference('epishkhan', 'organization', 'AG-100');

        $this->assertCount(1, $actors);
        $this->assertSame('org-agency', $actors[0]->organizationId);
        $this->assertNull($actors[0]->userId);
        $this->assertTrue($actors[0]->isOrganizationOnlyActor());
    }

    public function test_11_inactive_organization_target_fails_closed(): void
    {
        $this->storage->saveOrganization(new Organization('org-closed', 'Closed Agency', DirectoryStatus::Inactive));
        $this->storage->saveExternalReference('epishkhan', 'organization', 'AG-200', 'organization', 'org-closed', null);

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');

        $this->resolver->listAvailableActorsBySourceReference('epishkhan', 'organization', 'AG-200');
    }

    public function test_12_membership_role_is_not_surfaced_on_resolved_directory_actor(): void
    {
        $this->storage->saveOrganization(new Organization('org-1', 'Org One', DirectoryStatus::Active));
        $this->storage->saveUser(new User('usr-1', 'User One', DirectoryStatus::Active));
        $this->storage->saveMembership(new Membership(
            id: 'mem-1',
            userId: 'usr-1',
            organizationId: 'org-1',
            role: new OrganizationRole('super_admin_lead'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveExternalReference('auth', 'account', 'acc-1', 'user', null, 'usr-1');

        $actors = $this->resolver->listAvailableActorsBySourceReference('auth', 'account', 'acc-1');

        $this->assertCount(1, $actors);
        $actor = $actors[0];
        $this->assertSame('org-1', $actor->organizationId);
        $this->assertSame('usr-1', $actor->userId);
        // ResolvedDirectoryActor strictly contains only organizationId and userId
        $this->assertFalse(property_exists($actor, 'role'));
    }

    public function test_13_existing_resolve_by_source_reference_behavior_remains_unchanged(): void
    {
        $this->storage->saveOrganization(new Organization('org-1', 'Org One', DirectoryStatus::Active));
        $this->storage->saveUser(new User('usr-1', 'User One', DirectoryStatus::Active));
        $this->storage->saveMembership(new Membership(
            id: 'mem-1',
            userId: 'usr-1',
            organizationId: 'org-1',
            role: new OrganizationRole('operator'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable()
        ));
        $this->storage->saveExternalReference('auth', 'account', 'acc-1', 'user', null, 'usr-1');

        // Explicit org success
        $actor = $this->resolver->resolveBySourceReference('auth', 'account', 'acc-1', 'org-1');
        $this->assertSame('org-1', $actor->organizationId);
        $this->assertSame('usr-1', $actor->userId);

        // Missing explicit org fails closed
        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('explicit organization context');
        $this->resolver->resolveBySourceReference('auth', 'account', 'acc-1', null);
    }
}

final class InMemoryDirectoryStorage implements DirectoryStoragePortInterface
{
    /** @var array<string, Organization> */
    public array $organizations = [];
    /** @var array<string, User> */
    public array $users = [];
    /** @var array<string, Membership> */
    public array $memberships = [];
    /** @var array<string, object> */
    public array $references = [];

    public function saveOrganization(Organization $org): void
    {
        $this->organizations[$org->id] = $org;
    }

    public function findOrganization(string $id): ?Organization
    {
        return $this->organizations[$id] ?? null;
    }

    public function listOrganizations(?string $search = null, ?DirectoryStatus $status = null, int $limit = 100, int $offset = 0): array
    {
        return array_values($this->organizations);
    }

    public function countOrganizations(?string $search = null, ?DirectoryStatus $status = null): int
    {
        return count($this->organizations);
    }

    public function saveUser(User $user): void
    {
        $this->users[$user->id] = $user;
    }

    public function findUser(string $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function listUsers(?string $search = null, ?DirectoryStatus $status = null, int $limit = 100, int $offset = 0): array
    {
        return array_values($this->users);
    }

    public function countUsers(?string $search = null, ?DirectoryStatus $status = null): int
    {
        return count($this->users);
    }

    public function saveMembership(Membership $membership): void
    {
        $this->memberships[$membership->id] = $membership;
    }

    public function findMembershipById(string $id): ?Membership
    {
        return $this->memberships[$id] ?? null;
    }

    public function findActiveMembership(string $userId, string $organizationId): ?Membership
    {
        foreach ($this->memberships as $m) {
            if ($m->userId === $userId && $m->organizationId === $organizationId && $m->isActive()) {
                return $m;
            }
        }
        return null;
    }

    public function listMembershipsForUser(string $userId, ?DirectoryStatus $status = null): array
    {
        $res = [];
        foreach ($this->memberships as $m) {
            if ($m->userId === $userId) {
                if ($status === null || $m->status === $status) {
                    $res[] = $m;
                }
            }
        }
        return $res;
    }

    public function listMembershipsForOrganization(string $organizationId, ?DirectoryStatus $status = null): array
    {
        return [];
    }

    public function listMembershipsForUserAndOrganization(string $userId, string $organizationId): array
    {
        return [];
    }

    public function listMemberships(?string $userId = null, ?string $organizationId = null, ?DirectoryStatus $status = null, int $limit = 100, int $offset = 0): array
    {
        return array_values($this->memberships);
    }

    public function countMemberships(?string $userId = null, ?string $organizationId = null, ?DirectoryStatus $status = null): int
    {
        return count($this->memberships);
    }

    public function saveExternalReference(string $source, string $referenceKind, string $externalId, string $targetType, ?string $targetOrgId, ?string $targetUserId): void
    {
        $key = "{$source}:{$referenceKind}:{$externalId}";
        $this->references[$key] = (object) [
            'source' => $source,
            'reference_kind' => $referenceKind,
            'external_id' => $externalId,
            'target_type' => $targetType,
            'target_organization_id' => $targetOrgId,
            'target_user_id' => $targetUserId,
        ];
    }

    public function deleteExternalReference(string $source, string $referenceKind, string $externalId): void
    {
        $key = "{$source}:{$referenceKind}:{$externalId}";
        unset($this->references[$key]);
    }

    public function findExternalReference(string $source, string $referenceKind, string $externalId): ?object
    {
        $key = "{$source}:{$referenceKind}:{$externalId}";
        return $this->references[$key] ?? null;
    }
}
