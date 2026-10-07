<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bridges\WorkflowActorResolver;
use App\Bridges\WorkflowAuthorizationBridge;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Services\DirectoryBootstrapper;
use Karband\Directory\ValueObjects\OrganizationRole;
use Karband\DirectoryLaravel\Services\DirectoryStorage;
use Karband\DirectoryPanel\Services\DefaultDirectoryPanelAuthorization;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class DirectoryPanelTest extends TestCase
{
    use RefreshDatabase;

    private DirectoryPanelTestPrincipalProvider $principals;
    private DirectoryPanelTestScopes $scopes;
    private DirectoryPanelTestUser $user;
    private DirectoryStorage $storage;
    private DirectoryBootstrapper $bootstrapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create fine-grained directory permissions without requiring any role names
        foreach ([
            'directory.organization.list',
            'directory.organization.view',
            'directory.organization.create',
            'directory.organization.update',
            'directory.organization.archive',
            'directory.organization.restore',
            'directory.user.list',
            'directory.user.view',
            'directory.user.create',
            'directory.user.update',
            'directory.user.archive',
            'directory.user.restore',
            'directory.membership.list',
            'directory.membership.create',
            'directory.membership.update',
            'directory.membership.end',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->user = DirectoryPanelTestUser::create([
            'name' => 'Directory Test User',
            'email' => 'dirtest@example.test',
            'password' => 'secret',
        ]);

        $this->principals = new DirectoryPanelTestPrincipalProvider();
        $this->scopes = new DirectoryPanelTestScopes();
        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, $this->scopes);

        $this->storage = $this->app->make(DirectoryStorage::class);
        $this->bootstrapper = $this->app->make(DirectoryBootstrapper::class);
    }

    private function setActorPermissions(array $permissions, string $roleName = 'test_actor_role'): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $this->user->syncRoles([$roleName]);
        Auth::guard('web')->setUser($this->user);
        $actor = new ActorContext((string) $this->user->getAuthIdentifier(), $roleName, 'global');
        $this->principals->principal = new Principal($this->user, $actor, 'web');
    }

    // -------------------------------------------------------------
    // Organization Lifecycle & Panel Tests
    // -------------------------------------------------------------

    public function test_organization_creation_and_stable_identity(): void
    {
        $this->setActorPermissions([
            'directory.organization.list',
            'directory.organization.view',
            'directory.organization.create',
        ]);

        $this->get('/directory/organizations/create')
            ->assertStatus(200)
            ->assertSee('Create Organization');

        $response = $this->post('/directory/organizations', [
            'id' => 'org-alpha',
            'name' => 'Alpha Organization',
            'email' => 'alpha@org.test',
            'phone' => '+1234567890',
        ]);

        $response->assertRedirect('/directory/organizations/org-alpha');
        $response->assertSessionHas('success');

        $org = $this->storage->findOrganization('org-alpha');
        self::assertNotNull($org);
        self::assertSame('org-alpha', $org->id);
        self::assertSame('Alpha Organization', $org->name);
        self::assertSame('alpha@org.test', $org->email);
        self::assertTrue($org->isActive());
    }

    public function test_organization_metadata_update(): void
    {
        $this->setActorPermissions([
            'directory.organization.list',
            'directory.organization.view',
            'directory.organization.create',
            'directory.organization.update',
        ]);

        $this->storage->createOrganization('org-beta', 'Beta Old Name');

        $this->get('/directory/organizations/org-beta/edit')
            ->assertStatus(200)
            ->assertSee('Beta Old Name');

        $response = $this->put('/directory/organizations/org-beta', [
            'name' => 'Beta Updated Name',
            'email' => 'beta@new.test',
            'phone' => '+987654321',
        ]);

        $response->assertRedirect('/directory/organizations/org-beta');

        $updated = $this->storage->findOrganization('org-beta');
        self::assertSame('Beta Updated Name', $updated->name);
        self::assertSame('beta@new.test', $updated->email);
    }

    public function test_organization_archive_and_restore_lifecycle(): void
    {
        $this->setActorPermissions([
            'directory.organization.list',
            'directory.organization.view',
            'directory.organization.archive',
            'directory.organization.restore',
        ]);

        $this->storage->createOrganization('org-gamma', 'Gamma Org');

        // Archive
        $this->post('/directory/organizations/org-gamma/archive')
            ->assertRedirect('/directory/organizations/org-gamma');

        $archived = $this->storage->findOrganization('org-gamma');
        self::assertFalse($archived->isActive());
        self::assertSame(DirectoryStatus::Inactive, $archived->status);

        // Historical archived organization remains readable
        $detail = $this->get('/directory/organizations/org-gamma');
        $detail->assertStatus(200);
        $detail->assertSee('inactive');
        $detail->assertSee('Restore');

        // Restore
        $this->post('/directory/organizations/org-gamma/restore')
            ->assertRedirect('/directory/organizations/org-gamma');

        $restored = $this->storage->findOrganization('org-gamma');
        self::assertTrue($restored->isActive());
        self::assertSame(DirectoryStatus::Active, $restored->status);
    }

    // -------------------------------------------------------------
    // User Lifecycle & Panel Tests
    // -------------------------------------------------------------

    public function test_user_creation_and_stable_identity(): void
    {
        $this->setActorPermissions([
            'directory.user.list',
            'directory.user.view',
            'directory.user.create',
        ]);

        $this->get('/directory/users/create')
            ->assertStatus(200)
            ->assertSee('Create User');

        $response = $this->post('/directory/users', [
            'id' => 'usr-100',
            'display_name' => 'Doctor Ahmad',
            'email' => 'ahmad@doc.test',
            'phone' => '+989123456789',
        ]);

        $response->assertRedirect('/directory/users/usr-100');
        $response->assertSessionHas('success');

        $user = $this->storage->findUser('usr-100');
        self::assertNotNull($user);
        self::assertSame('usr-100', $user->id);
        self::assertSame('Doctor Ahmad', $user->displayName);
        self::assertSame('ahmad@doc.test', $user->email);
        self::assertTrue($user->isActive());
    }

    public function test_user_metadata_update(): void
    {
        $this->setActorPermissions([
            'directory.user.list',
            'directory.user.view',
            'directory.user.create',
            'directory.user.update',
        ]);

        $this->storage->createUser('usr-200', 'Original Name');

        $this->get('/directory/users/usr-200/edit')
            ->assertStatus(200)
            ->assertSee('Original Name');

        $response = $this->put('/directory/users/usr-200', [
            'display_name' => 'Renamed User',
            'email' => 'renamed@user.test',
            'phone' => '+111111111',
        ]);

        $response->assertRedirect('/directory/users/usr-200');

        $updated = $this->storage->findUser('usr-200');
        self::assertSame('Renamed User', $updated->displayName);
        self::assertSame('renamed@user.test', $updated->email);
    }

    public function test_user_archive_and_restore_lifecycle(): void
    {
        $this->setActorPermissions([
            'directory.user.list',
            'directory.user.view',
            'directory.user.archive',
            'directory.user.restore',
        ]);

        $this->storage->createUser('usr-300', 'Sara User');

        // Archive
        $this->post('/directory/users/usr-300/archive')
            ->assertRedirect('/directory/users/usr-300');

        $archived = $this->storage->findUser('usr-300');
        self::assertFalse($archived->isActive());
        self::assertSame(DirectoryStatus::Inactive, $archived->status);

        // Historical archived user remains readable
        $detail = $this->get('/directory/users/usr-300');
        $detail->assertStatus(200);
        $detail->assertSee('inactive');
        $detail->assertSee('Restore');

        // Restore
        $this->post('/directory/users/usr-300/restore')
            ->assertRedirect('/directory/users/usr-300');

        $restored = $this->storage->findUser('usr-300');
        self::assertTrue($restored->isActive());
        self::assertSame(DirectoryStatus::Active, $restored->status);
    }

    // -------------------------------------------------------------
    // Membership Lifecycle Tests
    // -------------------------------------------------------------

    public function test_membership_creation_connection_and_multi_org_support(): void
    {
        $this->setActorPermissions([
            'directory.organization.list',
            'directory.organization.view',
            'directory.user.list',
            'directory.user.view',
            'directory.membership.list',
            'directory.membership.create',
            'directory.membership.end',
        ]);

        $this->storage->createOrganization('org-m1', 'First Ministry');
        $this->storage->createOrganization('org-m2', 'Second Ministry');
        $this->storage->createUser('usr-m1', 'Multi Consultant');

        // Add membership to org-m1 with role
        $this->post('/directory/memberships', [
            'user_id' => 'usr-m1',
            'organization_id' => 'org-m1',
            'role' => 'advisor',
            'return_to' => 'organization',
        ])->assertRedirect('/directory/organizations/org-m1');

        // Add membership to org-m2 with role
        $this->post('/directory/memberships', [
            'user_id' => 'usr-m1',
            'organization_id' => 'org-m2',
            'role' => 'lead',
            'return_to' => 'organization',
        ])->assertRedirect('/directory/organizations/org-m2');

        // Prove user belongs to both organizations
        $memberships = $this->storage->listUserMemberships('usr-m1');
        self::assertCount(2, $memberships);
        self::assertTrue($memberships[0]->isActive());
        self::assertTrue($memberships[1]->isActive());

        // View from user detail page
        $detail = $this->get('/directory/users/usr-m1');
        $detail->assertStatus(200);
        $detail->assertSee('First Ministry');
        $detail->assertSee('Second Ministry');
        $detail->assertSee('advisor');
        $detail->assertSee('lead');
    }

    public function test_membership_ended_preserves_history_without_deletion(): void
    {
        $this->setActorPermissions([
            'directory.organization.list',
            'directory.organization.view',
            'directory.user.list',
            'directory.user.view',
            'directory.membership.list',
            'directory.membership.create',
            'directory.membership.end',
        ]);

        $this->storage->createOrganization('org-history', 'Historical Org');
        $this->storage->createUser('usr-history', 'Historical Employee');
        $this->storage->createMembership(
            id: 'mem-h1',
            userId: 'usr-history',
            organizationId: 'org-history',
            role: new OrganizationRole('staff'),
            status: DirectoryStatus::Active
        );

        // End membership via panel
        $this->post('/directory/memberships/end', [
            'user_id' => 'usr-history',
            'organization_id' => 'org-history',
            'return_to' => 'organization',
        ])->assertRedirect('/directory/organizations/org-history');

        // Membership record is preserved as inactive (never deleted)
        $mem = $this->storage->findMembership('usr-history', 'org-history');
        self::assertNotNull($mem);
        self::assertSame('mem-h1', $mem->id);
        self::assertFalse($mem->isActive());
        self::assertSame(DirectoryStatus::Inactive, $mem->status);
        self::assertNotNull($mem->endedAt);

        // Detail page still lists the ended membership with inactive badge and ended timestamp
        $orgDetail = $this->get('/directory/organizations/org-history');
        $orgDetail->assertStatus(200);
        $orgDetail->assertSee('usr-history');
        $orgDetail->assertSee('inactive');
        $orgDetail->assertSee('Reactivate');
    }

    // -------------------------------------------------------------
    // Authorization & Fail-Closed Tests
    // -------------------------------------------------------------

    public function test_read_only_actor_cannot_see_mutation_controls(): void
    {
        $this->storage->createOrganization('org-ro', 'Read Only Org');
        $this->storage->createUser('usr-ro', 'Read Only User');

        // Set actor permissions to read-only
        $this->setActorPermissions([
            'directory.organization.list',
            'directory.organization.view',
            'directory.user.list',
            'directory.user.view',
            'directory.membership.list',
        ], 'auditor_role');

        // Organization list: no create button
        $this->get('/directory/organizations')
            ->assertStatus(200)
            ->assertSee('Read Only Org')
            ->assertDontSee('+ New Organization');

        // Organization detail: no edit or archive or add member
        $this->get('/directory/organizations/org-ro')
            ->assertStatus(200)
            ->assertDontSee('Edit Organization')
            ->assertDontSee('Archive')
            ->assertDontSee('+ Add Member');

        // User list: no create button
        $this->get('/directory/users')
            ->assertStatus(200)
            ->assertSee('Read Only User')
            ->assertDontSee('+ New User');

        // User detail: no edit or archive
        $this->get('/directory/users/usr-ro')
            ->assertStatus(200)
            ->assertDontSee('Edit User')
            ->assertDontSee('Archive');
    }

    public function test_unauthorized_direct_mutations_fail_closed_with_403(): void
    {
        $this->storage->createOrganization('org-secure', 'Secure Org');
        $this->storage->createUser('usr-secure', 'Secure User');

        // Set actor with NO directory permissions
        $this->setActorPermissions([], 'unauthorized_guest');

        $this->get('/directory/organizations')->assertStatus(403);
        $this->get('/directory/organizations/create')->assertStatus(403);
        $this->post('/directory/organizations', ['id' => 'hack', 'name' => 'Hack'])->assertStatus(403);
        $this->get('/directory/organizations/org-secure')->assertStatus(403);
        $this->get('/directory/organizations/org-secure/edit')->assertStatus(403);
        $this->put('/directory/organizations/org-secure', ['name' => 'Hack'])->assertStatus(403);
        $this->post('/directory/organizations/org-secure/archive')->assertStatus(403);
        $this->post('/directory/organizations/org-secure/restore')->assertStatus(403);

        $this->get('/directory/users')->assertStatus(403);
        $this->get('/directory/users/create')->assertStatus(403);
        $this->post('/directory/users', ['id' => 'hack', 'display_name' => 'Hack'])->assertStatus(403);
        $this->get('/directory/users/usr-secure')->assertStatus(403);
        $this->get('/directory/users/usr-secure/edit')->assertStatus(403);
        $this->put('/directory/users/usr-secure', ['display_name' => 'Hack'])->assertStatus(403);
        $this->post('/directory/users/usr-secure/archive')->assertStatus(403);
        $this->post('/directory/users/usr-secure/restore')->assertStatus(403);

        $this->post('/directory/memberships', ['user_id' => 'u', 'organization_id' => 'o'])->assertStatus(403);
        $this->post('/directory/memberships/end', ['user_id' => 'u', 'organization_id' => 'o'])->assertStatus(403);
    }

    public function test_missing_authorization_wiring_fails_closed(): void
    {
        $defaultAuth = new DefaultDirectoryPanelAuthorization();

        self::assertFalse($defaultAuth->canListOrganizations());
        self::assertFalse($defaultAuth->canViewOrganization('any'));
        self::assertFalse($defaultAuth->canCreateOrganization());
        self::assertFalse($defaultAuth->canUpdateOrganization('any'));
        self::assertFalse($defaultAuth->canArchiveOrganization('any'));
        self::assertFalse($defaultAuth->canRestoreOrganization('any'));

        self::assertFalse($defaultAuth->canListUsers());
        self::assertFalse($defaultAuth->canViewUser('any'));
        self::assertFalse($defaultAuth->canCreateUser());
        self::assertFalse($defaultAuth->canUpdateUser('any'));
        self::assertFalse($defaultAuth->canArchiveUser('any'));
        self::assertFalse($defaultAuth->canRestoreUser('any'));

        self::assertFalse($defaultAuth->canListMemberships());
        self::assertFalse($defaultAuth->canCreateMembership());
        self::assertFalse($defaultAuth->canUpdateMembership());
        self::assertFalse($defaultAuth->canEndMembership());
    }

    // -------------------------------------------------------------
    // Bootstrap Model Tests
    // -------------------------------------------------------------

    public function test_bootstrap_creates_operator_organization_initial_user_and_membership(): void
    {
        $result = $this->bootstrapper->bootstrapOperator(
            operatorOrgId: 'operator-org-01',
            operatorOrgName: 'Platform Operations Department',
            initialUserId: 'admin-user-01',
            initialUserDisplayName: 'Platform Initial Administrator',
            membershipRole: new OrganizationRole('operator_lead'),
            operatorEmail: 'ops@platform.gov',
            userEmail: 'admin@platform.gov'
        );

        self::assertSame('operator-org-01', $result['organization']->id);
        self::assertSame('Platform Operations Department', $result['organization']->name);
        self::assertTrue($result['organization']->isActive());

        self::assertSame('admin-user-01', $result['user']->id);
        self::assertSame('Platform Initial Administrator', $result['user']->displayName);
        self::assertTrue($result['user']->isActive());

        self::assertSame('admin-user-01', $result['membership']->userId);
        self::assertSame('operator-org-01', $result['membership']->organizationId);
        self::assertSame('operator_lead', $result['membership']->role->value);
        self::assertTrue($result['membership']->isActive());

        // Idempotency check: running bootstrap again returns the existing facts without errors
        $repeat = $this->bootstrapper->bootstrapOperator(
            operatorOrgId: 'operator-org-01',
            operatorOrgName: 'Platform Operations Department',
            initialUserId: 'admin-user-01',
            initialUserDisplayName: 'Platform Initial Administrator',
            membershipRole: new OrganizationRole('operator_lead')
        );

        self::assertSame($result['organization']->id, $repeat['organization']->id);
        self::assertSame($result['user']->id, $repeat['user']->id);
        self::assertSame($result['membership']->id, $repeat['membership']->id);
    }
}

final class DirectoryPanelTestUser extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class DirectoryPanelTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class DirectoryPanelTestScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        $user = Auth::guard('web')->user();
        if (!$user instanceof BaseUser) {
            return false;
        }

        return $user->hasPermissionTo($request->ability, 'web');
    }
}
