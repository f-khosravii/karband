<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Intl\Directory\Contracts\DirectoryResolverInterface;
use Intl\Directory\Enums\DirectoryStatus;
use Intl\Directory\Enums\OrganizationRole;
use Intl\Directory\Exceptions\DirectoryActorResolutionException;
use Intl\Directory\Exceptions\DirectoryValidationException;
use Intl\DirectoryLaravel\Exceptions\DirectoryConflictException;
use Intl\DirectoryLaravel\Exceptions\DirectoryNotFoundException;
use Intl\DirectoryLaravel\Services\DirectoryResolver;
use Intl\DirectoryLaravel\Services\DirectoryStorage;
use Tests\TestCase;

final class IntlDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private DirectoryStorage $storage;
    private DirectoryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->app->make(DirectoryStorage::class);
        $this->resolver = $this->app->make(DirectoryResolver::class);
    }

    public function test_service_provider_binds_contract_and_services(): void
    {
        $this->assertInstanceOf(DirectoryStorage::class, $this->storage);
        $this->assertInstanceOf(DirectoryResolver::class, $this->resolver);
        $this->assertInstanceOf(DirectoryResolverInterface::class, $this->app->make(DirectoryResolverInterface::class));
    }

    public function test_organization_crud_and_status_lifecycle(): void
    {
        $org = $this->storage->createOrganization(
            id: 'org-100',
            name: 'National Medical Center',
            status: DirectoryStatus::Active,
            roles: [OrganizationRole::Provider, OrganizationRole::Operator],
            email: 'info@medical.gov',
            phone: '+982188888888'
        );

        $this->assertSame('org-100', $org->id);
        $this->assertSame('National Medical Center', $org->name);
        $this->assertTrue($org->isActive());
        $this->assertTrue($org->hasRole(OrganizationRole::Provider));
        $this->assertTrue($org->hasRole(OrganizationRole::Operator));
        $this->assertFalse($org->hasRole(OrganizationRole::Agency));

        // Duplicate creation fails
        $this->expectException(DirectoryConflictException::class);
        $this->storage->createOrganization('org-100', 'Duplicate Org');
    }

    public function test_organization_deactivate_and_activate(): void
    {
        $this->storage->createOrganization('org-101', 'Test Org');
        $deactivated = $this->storage->deactivateOrganization('org-101');
        $this->assertFalse($deactivated->isActive());

        $found = $this->storage->findOrganization('org-101');
        $this->assertNotNull($found);
        $this->assertFalse($found->isActive());

        $activated = $this->storage->activateOrganization('org-101');
        $this->assertTrue($activated->isActive());
    }

    public function test_organization_role_management(): void
    {
        $this->storage->createOrganization('org-201', 'Dual Role Org', DirectoryStatus::Active, [OrganizationRole::Agency]);
        $org = $this->storage->findOrganization('org-201');
        $this->assertNotNull($org);
        $this->assertTrue($org->hasRole(OrganizationRole::Agency));
        $this->assertFalse($org->hasRole(OrganizationRole::Provider));

        // Assign additional role
        $this->storage->assignOrganizationRole('org-201', OrganizationRole::Provider);
        $orgUpdated = $this->storage->findOrganization('org-201');
        $this->assertNotNull($orgUpdated);
        $this->assertTrue($orgUpdated->hasRole(OrganizationRole::Agency));
        $this->assertTrue($orgUpdated->hasRole(OrganizationRole::Provider));

        // Remove role
        $this->storage->removeOrganizationRole('org-201', OrganizationRole::Agency);
        $orgAfterRemove = $this->storage->findOrganization('org-201');
        $this->assertNotNull($orgAfterRemove);
        $this->assertFalse($orgAfterRemove->hasRole(OrganizationRole::Agency));
        $this->assertTrue($orgAfterRemove->hasRole(OrganizationRole::Provider));
    }

    public function test_user_crud_and_status_lifecycle(): void
    {
        $user = $this->storage->createUser(
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
        $deactivated = $this->storage->deactivateUser('usr-001');
        $this->assertFalse($deactivated->isActive());

        $found = $this->storage->findUser('usr-001');
        $this->assertNotNull($found);
        $this->assertFalse($found->isActive());

        // Activate user
        $activated = $this->storage->activateUser('usr-001');
        $this->assertTrue($activated->isActive());
    }

    public function test_membership_lifecycle_and_uniqueness(): void
    {
        $this->storage->createOrganization('org-300', 'Hospital 300');
        $this->storage->createUser('usr-300', 'Doctor John');

        $mem = $this->storage->createMembership('mem-300', 'usr-300', 'org-300', DirectoryStatus::Active);
        $this->assertSame('mem-300', $mem->id);
        $this->assertTrue($mem->isActive());

        // Duplicate membership creation fails
        $this->expectException(DirectoryConflictException::class);
        $this->storage->createMembership('mem-301', 'usr-300', 'org-300', DirectoryStatus::Active);
    }

    public function test_membership_status_change(): void
    {
        $this->storage->createOrganization('org-301', 'Hospital 301');
        $this->storage->createUser('usr-301', 'Doctor Jane');
        $this->storage->createMembership('mem-302', 'usr-301', 'org-301', DirectoryStatus::Active);

        $this->storage->setMembershipStatus('usr-301', 'org-301', DirectoryStatus::Inactive);
        $mem = $this->storage->findMembership('usr-301', 'org-301');
        $this->assertNotNull($mem);
        $this->assertFalse($mem->isActive());

        $this->storage->setMembershipStatus('usr-301', 'org-301', DirectoryStatus::Active);
        $memActive = $this->storage->findMembership('usr-301', 'org-301');
        $this->assertNotNull($memActive);
        $this->assertTrue($memActive->isActive());
    }

    public function test_external_reference_linking_and_uniqueness(): void
    {
        $this->storage->createOrganization('org-400', 'Agency 400', DirectoryStatus::Active, [OrganizationRole::Agency]);
        $this->storage->linkOrganizationReference('epishkhan', 'organization', 'AG-400', 'org-400');

        $ref = $this->storage->findExternalReference('epishkhan', 'organization', 'AG-400');
        $this->assertNotNull($ref);
        $this->assertSame('organization', $ref->target_type);
        $this->assertSame('org-400', $ref->target_organization_id);

        // Duplicate link fails
        $this->expectException(DirectoryConflictException::class);
        $this->storage->linkOrganizationReference('epishkhan', 'organization', 'AG-400', 'org-400');
    }

    public function test_resolution_organization_success(): void
    {
        $this->storage->createOrganization('org-500', 'Agency 500', DirectoryStatus::Active, [OrganizationRole::Agency]);
        $this->storage->linkOrganizationReference('epishkhan', 'organization', 'AG-500', 'org-500');

        $actor = $this->resolver->resolveBySourceReference('epishkhan', 'organization', 'AG-500');
        $this->assertSame('org-500', $actor->organizationId);
        $this->assertNull($actor->userId);
        $this->assertTrue($actor->isOrganizationOnlyActor());
    }

    public function test_resolution_organization_inactive_fails_closed(): void
    {
        $this->storage->createOrganization('org-501', 'Inactive Agency', DirectoryStatus::Inactive, [OrganizationRole::Agency]);
        $this->storage->linkOrganizationReference('epishkhan', 'organization', 'AG-501', 'org-501');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'organization', 'AG-501');
    }

    public function test_resolution_user_with_explicit_org_success(): void
    {
        $this->storage->createOrganization('org-600', 'Ministry of Finance', DirectoryStatus::Active, [OrganizationRole::Operator]);
        $this->storage->createUser('usr-600', 'Operator Ali', DirectoryStatus::Active);
        $this->storage->createMembership('mem-600', 'usr-600', 'org-600', DirectoryStatus::Active);
        $this->storage->linkUserReference('epishkhan', 'subject', 'SUBJ-600', 'usr-600');

        $actor = $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-600', 'org-600');
        $this->assertSame('org-600', $actor->organizationId);
        $this->assertSame('usr-600', $actor->userId);
        $this->assertTrue($actor->isUserActor());
    }

    public function test_resolution_user_without_explicit_org_fails_closed(): void
    {
        $this->storage->createOrganization('org-601', 'Org 601', DirectoryStatus::Active);
        $this->storage->createUser('usr-601', 'User 601', DirectoryStatus::Active);
        $this->storage->createMembership('mem-601', 'usr-601', 'org-601', DirectoryStatus::Active);
        $this->storage->linkUserReference('epishkhan', 'subject', 'SUBJ-601', 'usr-601');

        // No explicit org context passed -> MUST fail closed, never choose first membership
        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('explicit organization context');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-601');
    }

    public function test_resolution_user_inactive_user_fails_closed(): void
    {
        $this->storage->createOrganization('org-602', 'Org 602', DirectoryStatus::Active);
        $this->storage->createUser('usr-602', 'User 602', DirectoryStatus::Inactive);
        $this->storage->createMembership('mem-602', 'usr-602', 'org-602', DirectoryStatus::Active);
        $this->storage->linkUserReference('epishkhan', 'subject', 'SUBJ-602', 'usr-602');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-602', 'org-602');
    }

    public function test_resolution_user_inactive_membership_fails_closed(): void
    {
        $this->storage->createOrganization('org-603', 'Org 603', DirectoryStatus::Active);
        $this->storage->createUser('usr-603', 'User 603', DirectoryStatus::Active);
        $this->storage->createMembership('mem-603', 'usr-603', 'org-603', DirectoryStatus::Inactive);
        $this->storage->linkUserReference('epishkhan', 'subject', 'SUBJ-603', 'usr-603');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-603', 'org-603');
    }

    public function test_resolution_user_inactive_organization_fails_closed(): void
    {
        $this->storage->createOrganization('org-604', 'Org 604', DirectoryStatus::Inactive);
        $this->storage->createUser('usr-604', 'User 604', DirectoryStatus::Active);
        $this->storage->createMembership('mem-604', 'usr-604', 'org-604', DirectoryStatus::Active);
        $this->storage->linkUserReference('epishkhan', 'subject', 'SUBJ-604', 'usr-604');

        $this->expectException(DirectoryActorResolutionException::class);
        $this->expectExceptionMessage('missing or inactive');
        $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-604', 'org-604');
    }

    public function test_resolution_user_multi_membership_explicit_selection(): void
    {
        $this->storage->createOrganization('org-A', 'Ministry A', DirectoryStatus::Active);
        $this->storage->createOrganization('org-B', 'Ministry B', DirectoryStatus::Active);
        $this->storage->createUser('usr-multi', 'Multi Org User', DirectoryStatus::Active);
        $this->storage->createMembership('mem-A', 'usr-multi', 'org-A', DirectoryStatus::Active);
        $this->storage->createMembership('mem-B', 'usr-multi', 'org-B', DirectoryStatus::Active);
        $this->storage->linkUserReference('epishkhan', 'subject', 'SUBJ-MULTI', 'usr-multi');

        // Resolve for org-A
        $actorA = $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-MULTI', 'org-A');
        $this->assertSame('org-A', $actorA->organizationId);
        $this->assertSame('usr-multi', $actorA->userId);

        // Resolve for org-B
        $actorB = $this->resolver->resolveBySourceReference('epishkhan', 'subject', 'SUBJ-MULTI', 'org-B');
        $this->assertSame('org-B', $actorB->organizationId);
        $this->assertSame('usr-multi', $actorB->userId);

        // Resolve for org-C (not a member) -> fails closed
        $this->storage->createOrganization('org-C', 'Ministry C', DirectoryStatus::Active);
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
}
