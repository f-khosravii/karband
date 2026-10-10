<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\AuthorizerInterface;
use Karband\Directory\Contracts\DirectoryResolverInterface;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryActorResolutionException;
use Karband\Directory\Exceptions\DirectoryValidationException;
use Karband\Directory\Services\DirectoryBootstrapper;
use Karband\Directory\Services\DirectoryResolver;
use Karband\Directory\Services\DirectoryService;
use Karband\Directory\ValueObjects\OrganizationRole;
use Karband\Directory\Exceptions\DirectoryConflictException;
use Karband\Directory\Exceptions\DirectoryNotFoundException;
use Karband\DirectoryLaravel\Services\DirectoryStorage;
use Tests\TestCase;

final class DirectoryCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private DirectoryStorage $storage;
    private DirectoryService $service;
    private DirectoryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->app->make(DirectoryStorage::class);
        $this->service = $this->app->make(DirectoryService::class);
        $this->resolver = $this->app->make(DirectoryResolver::class);
    }

    public function test_service_provider_binds_contract_and_services(): void
    {
        $this->assertInstanceOf(DirectoryStorage::class, $this->storage);
        $this->assertInstanceOf(DirectoryService::class, $this->service);
        $this->assertInstanceOf(DirectoryResolver::class, $this->resolver);
        $this->assertInstanceOf(DirectoryResolverInterface::class, $this->app->make(DirectoryResolverInterface::class));
    }

    public function test_organization_crud_and_status_lifecycle(): void
    {
        $org = $this->service->createOrganization(
            id: 'org-100',
            name: 'National Medical Center',
            status: DirectoryStatus::Active,
            email: 'info@medical.gov',
            phone: '+982188888888'
        );

        $this->assertSame('org-100', $org->id);
        $this->assertSame('National Medical Center', $org->name);
        $this->assertTrue($org->isActive());

        // Duplicate creation fails
        $this->expectException(DirectoryConflictException::class);
        $this->service->createOrganization('org-100', 'Duplicate Org');
    }

    public function test_organization_deactivate_and_activate(): void
    {
        $this->service->createOrganization('org-101', 'Test Org');
        $deactivated = $this->service->archiveOrganization('org-101');
        $this->assertFalse($deactivated->isActive());

        $found = $this->storage->findOrganization('org-101');
        $this->assertNotNull($found);
        $this->assertFalse($found->isActive());

        $activated = $this->service->restoreOrganization('org-101');
        $this->assertTrue($activated->isActive());
    }

    public function test_user_crud_and_status_lifecycle(): void
    {
        $user = $this->service->createUser(
            id: 'usr-001',
            displayName: 'Sara Operator',
            status: DirectoryStatus::Active,
            email: 'sara@platform.gov',
            phone: '+989121111111'
        );

        $this->assertSame('usr-001', $user->id);
        $this->assertSame('Sara Operator', $user->displayName);
        $this->assertTrue($user->isActive());

        // Deactivate user
        $deactivated = $this->service->archiveUser('usr-001');
        $this->assertFalse($deactivated->isActive());

        $found = $this->storage->findUser('usr-001');
        $this->assertNotNull($found);
        $this->assertFalse($found->isActive());

        // Activate user
        $activated = $this->service->restoreUser('usr-001');
        $this->assertTrue($activated->isActive());
    }

    public function test_membership_lifecycle_and_uniqueness(): void
    {
        $this->service->createOrganization('org-300', 'Hospital 300');
        $this->service->createUser('usr-300', 'Doctor John');

        $mem = $this->service->createMembership(
            id: 'mem-300',
            userId: 'usr-300',
            organizationId: 'org-300',
            role: new OrganizationRole('staff'),
            status: DirectoryStatus::Active
        );
        $this->assertSame('mem-300', $mem->id);
        $this->assertTrue($mem->isActive());
        $this->assertSame('staff', $mem->role->value);

        // Duplicate active membership creation fails
        $this->expectException(DirectoryConflictException::class);
        $this->service->createMembership('mem-301', 'usr-300', 'org-300', new OrganizationRole('member'), DirectoryStatus::Active);
    }

    /**
     * Requirement: Join at T1 -> End at T2 -> Rejoin at T3.
     * Directory must truthfully preserve the prior ended period T1->T2 independently of Audit.
     */
    public function test_membership_join_end_rejoin_preserves_distinct_periods_independently_of_audit(): void
    {
        $this->service->createOrganization('org-timeline', 'Timeline Hospital');
        $this->service->createUser('usr-doctor', 'Doctor Timeline');

        // T1: Join
        $t1 = new DateTimeImmutable('2025-01-01 09:00:00');
        $mem1 = $this->service->createMembership(
            id: 'mem-period-1',
            userId: 'usr-doctor',
            organizationId: 'org-timeline',
            role: new OrganizationRole('resident'),
            status: DirectoryStatus::Active,
            now: $t1
        );
        $this->assertSame('mem-period-1', $mem1->id);
        $this->assertTrue($mem1->isActive());
        $this->assertSame('resident', $mem1->role->value);
        $this->assertNull($mem1->endedAt);

        // T2: End membership
        $t2 = new DateTimeImmutable('2025-06-30 18:00:00');
        $mem1Ended = $this->service->endMembership('usr-doctor', 'org-timeline', $t2);
        $this->assertSame('mem-period-1', $mem1Ended->id);
        $this->assertFalse($mem1Ended->isActive());
        $this->assertSame('2025-06-30 18:00:00', $mem1Ended->endedAt?->format('Y-m-d H:i:s'));

        // Verify that currently active membership is null
        $activeCurrent = $this->storage->findActiveMembership('usr-doctor', 'org-timeline');
        $this->assertNull($activeCurrent);

        // T3: Rejoin (new distinct membership period with new role)
        $t3 = new DateTimeImmutable('2026-01-15 08:00:00');
        $mem2 = $this->service->createMembership(
            id: 'mem-period-2',
            userId: 'usr-doctor',
            organizationId: 'org-timeline',
            role: new OrganizationRole('specialist'),
            status: DirectoryStatus::Active,
            now: $t3
        );
        $this->assertSame('mem-period-2', $mem2->id);
        $this->assertTrue($mem2->isActive());
        $this->assertSame('specialist', $mem2->role->value);
        $this->assertSame('2026-01-15 08:00:00', $mem2->createdAt?->format('Y-m-d H:i:s'));
        $this->assertNull($mem2->endedAt);

        // PROVE Directory retrieves both the ended T1->T2 period AND the active T3 period from Directory storage
        $allMemberships = $this->storage->listMembershipsForUserAndOrganization('usr-doctor', 'org-timeline');
        $this->assertCount(2, $allMemberships);

        // Older period (T1 -> T2)
        $olderPeriod = $allMemberships[1]; // ordered by created_at DESC, so index 1 is older
        $this->assertSame('mem-period-1', $olderPeriod->id);
        $this->assertSame(DirectoryStatus::Inactive, $olderPeriod->status);
        $this->assertSame('resident', $olderPeriod->role->value);
        $this->assertSame('2025-01-01 09:00:00', $olderPeriod->createdAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2025-06-30 18:00:00', $olderPeriod->endedAt?->format('Y-m-d H:i:s'));

        // Current period (T3 -> present)
        $newPeriod = $allMemberships[0];
        $this->assertSame('mem-period-2', $newPeriod->id);
        $this->assertSame(DirectoryStatus::Active, $newPeriod->status);
        $this->assertSame('specialist', $newPeriod->role->value);
        $this->assertSame('2026-01-15 08:00:00', $newPeriod->createdAt?->format('Y-m-d H:i:s'));
        $this->assertNull($newPeriod->endedAt);
    }

    public function test_external_reference_linking_and_uniqueness(): void
    {
        $this->service->createOrganization('org-400', 'Agency 400', DirectoryStatus::Active);
        $this->service->linkOrganizationReference('epishkhan', 'organization', 'AG-400', 'org-400');

        $ref = $this->storage->findExternalReference('epishkhan', 'organization', 'AG-400');
        $this->assertNotNull($ref);
        $this->assertSame('organization', $ref->target_type);
        $this->assertSame('org-400', $ref->target_organization_id);

        // Duplicate link fails
        $this->expectException(DirectoryConflictException::class);
        $this->service->linkOrganizationReference('epishkhan', 'organization', 'AG-400', 'org-400');
    }

    public function test_resolution_organization_success(): void
    {
        $this->service->createOrganization('org-500', 'Agency 500', DirectoryStatus::Active);
        $this->service->linkOrganizationReference('epishkhan', 'organization', 'AG-500', 'org-500');

        $actor = $this->resolver->resolveBySourceReference('epishkhan', 'organization', 'AG-500');
        $this->assertSame('org-500', $actor->organizationId);
        $this->assertNull($actor->userId);
        $this->assertTrue($actor->isOrganizationOnlyActor());
    }

    public function test_resolution_organization_inactive_fails_closed(): void
    {
        $this->service->createOrganization('org-501', 'Inactive Agency', DirectoryStatus::Inactive);
        $this->service->linkOrganizationReference('epishkhan', 'organization', 'AG-501', 'org-501');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'organization', 'AG-501');
    }

    public function test_resolution_user_with_explicit_org_success(): void
    {
        $this->service->createOrganization('org-600', 'Ministry of Finance', DirectoryStatus::Active);
        $this->service->createUser('usr-600', 'Operator Ali', DirectoryStatus::Active);
        $this->service->createMembership('mem-600', 'usr-600', 'org-600', new OrganizationRole('operator'), DirectoryStatus::Active);
        $this->service->linkUserReference('epishkhan', 'subject', 'SUBJ-600', 'usr-600');

        $actor = $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-600', 'org-600');
        $this->assertSame('org-600', $actor->organizationId);
        $this->assertSame('usr-600', $actor->userId);
        $this->assertTrue($actor->isUserActor());
    }

    public function test_resolution_user_without_explicit_org_fails_closed(): void
    {
        $this->service->createOrganization('org-601', 'Org 601', DirectoryStatus::Active);
        $this->service->createUser('usr-601', 'User 601', DirectoryStatus::Active);
        $this->service->createMembership('mem-601', 'usr-601', 'org-601', new OrganizationRole('member'), DirectoryStatus::Active);
        $this->service->linkUserReference('epishkhan', 'subject', 'SUBJ-601', 'usr-601');

        // No explicit org context passed -> MUST fail closed, never choose first membership
        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('explicit organization context');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-601');
    }

    public function test_resolution_user_inactive_user_fails_closed(): void
    {
        $this->service->createOrganization('org-602', 'Org 602', DirectoryStatus::Active);
        $this->service->createUser('usr-602', 'User 602', DirectoryStatus::Inactive);
        $this->service->createMembership('mem-602', 'usr-602', 'org-602', new OrganizationRole('member'), DirectoryStatus::Active);
        $this->service->linkUserReference('epishkhan', 'subject', 'SUBJ-602', 'usr-602');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-602', 'org-602');
    }

    public function test_resolution_user_inactive_membership_fails_closed(): void
    {
        $this->service->createOrganization('org-603', 'Org 603', DirectoryStatus::Active);
        $this->service->createUser('usr-603', 'User 603', DirectoryStatus::Active);
        $this->service->createMembership('mem-603', 'usr-603', 'org-603', new OrganizationRole('member'), DirectoryStatus::Inactive);
        $this->service->linkUserReference('epishkhan', 'subject', 'SUBJ-603', 'usr-603');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-603', 'org-603');
    }

    public function test_resolution_user_inactive_organization_fails_closed(): void
    {
        $this->service->createOrganization('org-604', 'Org 604', DirectoryStatus::Inactive);
        $this->service->createUser('usr-604', 'User 604', DirectoryStatus::Active);
        $this->service->createMembership('mem-604', 'usr-604', 'org-604', new OrganizationRole('member'), DirectoryStatus::Active);
        $this->service->linkUserReference('epishkhan', 'subject', 'SUBJ-604', 'usr-604');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-604', 'org-604');
    }

    public function test_resolution_user_multi_membership_explicit_selection(): void
    {
        $this->service->createOrganization('org-A', 'Ministry A', DirectoryStatus::Active);
        $this->service->createOrganization('org-B', 'Ministry B', DirectoryStatus::Active);
        $this->service->createUser('usr-multi', 'Multi Org User', DirectoryStatus::Active);
        $this->service->createMembership('mem-A', 'usr-multi', 'org-A', new OrganizationRole('advisor'), DirectoryStatus::Active);
        $this->service->createMembership('mem-B', 'usr-multi', 'org-B', new OrganizationRole('manager'), DirectoryStatus::Active);
        $this->service->linkUserReference('epishkhan', 'subject', 'SUBJ-MULTI', 'usr-multi');

        // Resolve for org-A
        $actorA = $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-MULTI', 'org-A');
        $this->assertSame('org-A', $actorA->organizationId);
        $this->assertSame('usr-multi', $actorA->userId);

        // Resolve for org-B
        $actorB = $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-MULTI', 'org-B');
        $this->assertSame('org-B', $actorB->organizationId);
        $this->assertSame('usr-multi', $actorB->userId);

        // Resolve for org-C (not a member) -> fails closed
        $this->service->createOrganization('org-C', 'Ministry C', DirectoryStatus::Active);
        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-MULTI', 'org-C');
    }

    public function test_resolution_unknown_reference_fails_closed(): void
    {
        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('Unknown source reference');
        $this->resolver->resolveBySourceReference('epishkhan', 'unknown_kind', 'NONEXISTENT');
    }

    public function test_organization_list_search_and_archive_restore(): void
    {
        $this->service->createOrganization('org-search-1', 'Tax Authority North', DirectoryStatus::Active, email: 'north@tax.gov');
        $this->service->createOrganization('org-search-2', 'Tax Authority South', DirectoryStatus::Active, email: 'south@tax.gov');
        $this->service->createOrganization('org-search-3', 'Customs Office', DirectoryStatus::Inactive);

        // List all
        $all = $this->storage->listOrganizations();
        self::assertCount(3, $all);

        // Search by name
        $filtered = $this->storage->listOrganizations(search: 'North');
        self::assertCount(1, $filtered);
        self::assertSame('org-search-1', $filtered[0]->id);

        // Filter by status
        $inactive = $this->storage->listOrganizations(status: DirectoryStatus::Inactive);
        self::assertCount(1, $inactive);
        self::assertSame('org-search-3', $inactive[0]->id);

        // Count
        self::assertSame(2, $this->storage->countOrganizations(search: 'Tax'));

        // Archive & Restore aliases
        $archived = $this->service->archiveOrganization('org-search-1');
        self::assertFalse($archived->isActive());
        $restored = $this->service->restoreOrganization('org-search-1');
        self::assertTrue($restored->isActive());
    }

    public function test_user_list_search_and_archive_restore(): void
    {
        $this->service->createUser('usr-s1', 'Ahmad Taxpayer', DirectoryStatus::Active, email: 'ahmad@test.com');
        $this->service->createUser('usr-s2', 'Sara Inspector', DirectoryStatus::Active, email: 'sara@test.com');
        $this->service->createUser('usr-s3', 'Inactive Person', DirectoryStatus::Inactive);

        // List all
        $all = $this->storage->listUsers();
        self::assertCount(3, $all);

        // Search
        $found = $this->storage->listUsers(search: 'Sara');
        self::assertCount(1, $found);
        self::assertSame('usr-s2', $found[0]->id);

        // Filter by status
        $active = $this->storage->listUsers(status: DirectoryStatus::Active);
        self::assertCount(2, $active);

        // Count
        self::assertSame(1, $this->storage->countUsers(search: 'Ahmad'));

        // Archive & Restore aliases
        $archived = $this->service->archiveUser('usr-s1');
        self::assertFalse($archived->isActive());
        $restored = $this->service->restoreUser('usr-s1');
        self::assertTrue($restored->isActive());
    }

    public function test_membership_list_end_and_restore(): void
    {
        $this->service->createOrganization('org-ml1', 'Ministry L1');
        $this->service->createOrganization('org-ml2', 'Ministry L2');
        $this->service->createUser('usr-ml1', 'User ML1');

        $this->service->createMembership('mem-ml1', 'usr-ml1', 'org-ml1', new OrganizationRole('staff'), DirectoryStatus::Active);
        $this->service->createMembership('mem-ml2', 'usr-ml1', 'org-ml2', new OrganizationRole('staff'), DirectoryStatus::Active);

        // List by org
        $orgMembers = $this->storage->listOrganizationMemberships('org-ml1');
        self::assertCount(1, $orgMembers);
        self::assertSame('usr-ml1', $orgMembers[0]->userId);

        // List all memberships
        $allMem = $this->storage->listMemberships(userId: 'usr-ml1');
        self::assertCount(2, $allMem);
        self::assertSame(2, $this->storage->countMemberships(userId: 'usr-ml1'));

        // End membership
        $ended = $this->service->endMembership('usr-ml1', 'org-ml1');
        self::assertFalse($ended->isActive());

        // Restore membership
        $restored = $this->service->restoreMembership('usr-ml1', 'org-ml1');
        self::assertTrue($restored->isActive());
    }

    public function test_directory_bootstrapper_creates_facts_without_privilege_grants(): void
    {
        $bootstrapper = $this->app->make(DirectoryBootstrapper::class);

        $facts = $bootstrapper->bootstrapOperator(
            operatorOrgId: 'operator-root-01',
            operatorOrgName: 'Central Operator Organization',
            initialUserId: 'root-user-01',
            initialUserDisplayName: 'Root User',
            membershipRole: new OrganizationRole('administrator'),
            operatorEmail: 'ops@root.gov',
            userEmail: 'admin@root.gov'
        );

        self::assertSame('operator-root-01', $facts['organization']->id);
        self::assertSame('Central Operator Organization', $facts['organization']->name);
        self::assertSame('root-user-01', $facts['user']->id);
        self::assertSame('Root User', $facts['user']->displayName);
        self::assertSame('root-user-01', $facts['membership']->userId);
        self::assertSame('operator-root-01', $facts['membership']->organizationId);
        self::assertSame('administrator', $facts['membership']->role->value);

        // Prove bootstrap alone grants ZERO Authorization permissions
        $actor = new \Karband\Authorization\ActorContext(
            id: 'root-user-01',
            role: 'default',
            organizationId: 'operator-root-01'
        );

        $authorizer = new class implements AuthorizerInterface {
            public function authorize(\Karband\Authorization\ActorContext $actor, AccessRequest $request): void {
                throw new \Karband\Authorization\AuthorizationDenied('No permissions granted by Directory facts.');
            }
        };

        $this->expectException(\Karband\Authorization\AuthorizationDenied::class);
        $authorizer->authorize($actor, new AccessRequest('directory.organization.create', 'directory.organization', 'any'));
    }

    public function test_membership_role_does_not_implicitly_grant_authorization_permissions(): void
    {
        $this->service->createOrganization('org-priv-test', 'Privileged Tagged Org');
        $this->service->createUser('user-unprivileged', 'Plain User');
        $mem = $this->service->createMembership(
            id: 'mem-custom-role',
            userId: 'user-unprivileged',
            organizationId: 'org-priv-test',
            role: new OrganizationRole('operator_lead')
        );

        $this->assertSame('operator_lead', $mem->role->value);

        $actor = new \Karband\Authorization\ActorContext(
            id: 'user-unprivileged',
            role: 'default',
            organizationId: 'org-priv-test'
        );

        $authorizer = new class implements AuthorizerInterface {
            public function authorize(\Karband\Authorization\ActorContext $actor, AccessRequest $request): void {
                throw new \Karband\Authorization\AuthorizationDenied('OrganizationRole on Membership does not grant authorization permissions.');
            }
        };

        $this->expectException(\Karband\Authorization\AuthorizationDenied::class);
        $authorizer->authorize($actor, new AccessRequest('catalog.service.create', 'catalog.service', 'test'));
    }

    public function test_bootstrap_operator_strictly_requires_explicit_membership_role(): void
    {
        $reflection = new \ReflectionMethod(DirectoryBootstrapper::class, 'bootstrapOperator');
        $params = $reflection->getParameters();
        $roleParam = null;
        foreach ($params as $param) {
            if ($param->getName() === 'membershipRole') {
                $roleParam = $param;
                break;
            }
        }

        self::assertNotNull($roleParam, 'bootstrapOperator must have a membershipRole parameter');
        self::assertFalse($roleParam->isOptional(), 'membershipRole parameter must be strictly required with no default value');
        self::assertFalse($roleParam->isDefaultValueAvailable(), 'membershipRole must not have an implicit fallback default value');
    }

    public function test_active_membership_database_level_concurrency_uniqueness_and_history_invariants(): void
    {
        $this->service->createOrganization('org-atomic-1', 'Atomic Invariant Org');
        $this->service->createUser('usr-atomic-1', 'Atomic Invariant User');

        // 1. First active membership succeeds
        $mem1 = new \Karband\Directory\ValueObjects\Membership(
            id: 'mem-atomic-p1',
            userId: 'usr-atomic-1',
            organizationId: 'org-atomic-1',
            role: new OrganizationRole('operator'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable('2026-01-01 10:00:00')
        );
        $this->storage->saveMembership($mem1);
        self::assertNotNull($this->storage->findActiveMembership('usr-atomic-1', 'org-atomic-1'));

        // 2. Direct storage insert of a concurrent second active membership for same user+org fails at DB constraint
        $memDuplicateActive = new \Karband\Directory\ValueObjects\Membership(
            id: 'mem-atomic-dup',
            userId: 'usr-atomic-1',
            organizationId: 'org-atomic-1',
            role: new OrganizationRole('consultant'),
            status: DirectoryStatus::Active,
            createdAt: new DateTimeImmutable('2026-01-02 10:00:00')
        );

        $caught = false;
        try {
            $this->storage->saveMembership($memDuplicateActive);
        } catch (DirectoryConflictException $e) {
            $caught = true;
            self::assertStringContainsString('Active membership already exists', $e->getMessage());
        }
        self::assertTrue($caught, 'Database unique constraint must reject concurrent duplicate active membership');

        // 3. Ending the active membership allows a new active period
        $this->service->endMembership('usr-atomic-1', 'org-atomic-1', new DateTimeImmutable('2026-02-01 18:00:00'));
        self::assertNull($this->storage->findActiveMembership('usr-atomic-1', 'org-atomic-1'));

        // 4. Multiple inactive historical periods are allowed
        $memHistory2 = new \Karband\Directory\ValueObjects\Membership(
            id: 'mem-atomic-p2',
            userId: 'usr-atomic-1',
            organizationId: 'org-atomic-1',
            role: new OrganizationRole('auditor'),
            status: DirectoryStatus::Inactive,
            createdAt: new DateTimeImmutable('2026-02-05 08:00:00'),
            endedAt: new DateTimeImmutable('2026-02-28 17:00:00')
        );
        $this->storage->saveMembership($memHistory2);

        // 5. New active period succeeds
        $mem3 = $this->service->rejoinMembership('mem-atomic-p3', 'usr-atomic-1', 'org-atomic-1', new OrganizationRole('lead'), new DateTimeImmutable('2026-03-01 09:00:00'));
        self::assertTrue($mem3->isActive());

        // 6. Old periods remain readable and preserved
        $history = $this->storage->listMembershipsForUserAndOrganization('usr-atomic-1', 'org-atomic-1');
        self::assertCount(3, $history);
    }

    public function test_resolver_discovery_with_membership_history_filters_inactive_and_ended_periods(): void
    {
        // Scenario from requirement: User U1 with O1 active, O2 ended, O3 active
        $this->service->createOrganization('O1', 'Organization 1', DirectoryStatus::Active);
        $this->service->createOrganization('O2', 'Organization 2', DirectoryStatus::Active);
        $this->service->createOrganization('O3', 'Organization 3', DirectoryStatus::Active);

        $this->service->createUser('U1', 'User 1', DirectoryStatus::Active);

        $this->service->createMembership('M-O1', 'U1', 'O1', new OrganizationRole('operator'), DirectoryStatus::Active);
        $this->service->createMembership('M-O2', 'U1', 'O2', new OrganizationRole('member'), DirectoryStatus::Active);
        $this->service->endMembership('U1', 'O2', new DateTimeImmutable('-1 day'));
        $this->service->createMembership('M-O3', 'U1', 'O3', new OrganizationRole('lead'), DirectoryStatus::Active);

        $this->service->linkUserReference('auth-provider', 'account', 'ACC-U1', 'U1');

        $candidates = $this->resolver->listAvailableActorsBySourceReference('auth-provider', 'account', 'ACC-U1');

        $this->assertCount(2, $candidates);

        $orgIds = array_map(fn ($actor) => $actor->organizationId, $candidates);
        $this->assertContains('O1', $orgIds);
        $this->assertContains('O3', $orgIds);
        $this->assertNotContains('O2', $orgIds);

        foreach ($candidates as $actor) {
            $this->assertSame('U1', $actor->userId);
        }
    }

    public function test_sandbox_consumer_discovery_and_final_resolution_without_storage_ports(): void
    {
        // Provision Directory facts
        $this->service->createOrganization('org-consumer-1', 'Consumer Org 1', DirectoryStatus::Active);
        $this->service->createOrganization('org-consumer-2', 'Consumer Org 2', DirectoryStatus::Active);
        $this->service->createUser('usr-consumer', 'Consumer User', DirectoryStatus::Active);

        $this->service->createMembership('mem-c1', 'usr-consumer', 'org-consumer-1', new OrganizationRole('auditor'), DirectoryStatus::Active);
        $this->service->createMembership('mem-c2', 'usr-consumer', 'org-consumer-2', new OrganizationRole('consultant'), DirectoryStatus::Active);

        $this->service->linkUserReference('authentication', 'account', 'ACC-CONSUMER-99', 'usr-consumer');

        // CONSUMER FLOW:
        // 1. Consume ONLY DirectoryResolverInterface (pure public domain contract)
        /** @var DirectoryResolverInterface $resolverInterface */
        $resolverInterface = $this->app->make(DirectoryResolverInterface::class);

        // 2. Pre-selection discovery
        $availableContexts = $resolverInterface->listAvailableActorsBySourceReference(
            source: 'authentication',
            referenceKind: 'account',
            externalId: 'ACC-CONSUMER-99'
        );

        $this->assertCount(2, $availableContexts);

        // 3. User chooses 'org-consumer-2' from discovered candidates
        $selectedOrgId = 'org-consumer-2';

        // 4. Final authoritative resolution
        $resolvedActor = $resolverInterface->resolveBySourceReference(
            source: 'authentication',
            referenceKind: 'account',
            externalId: 'ACC-CONSUMER-99',
            explicitOrganizationId: $selectedOrgId
        );

        $this->assertSame('org-consumer-2', $resolvedActor->organizationId);
        $this->assertSame('usr-consumer', $resolvedActor->userId);
    }
}
