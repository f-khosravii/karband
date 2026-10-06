<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Intl\AuthorizationLaravel\Bridge\WorkflowActorResolver;
use Intl\AuthorizationLaravel\Bridge\WorkflowAuthorizationBridge;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Contracts\ScopeChecker;
use Intl\AuthorizationLaravel\Principal;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\Workflow\ValueObjects\ActorContext as WorkflowActorContext;
use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

/** Requires isolated test database plus Spatie's published migrations; never run against NCR. */
final class IntlAuthorizationAdapterTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationTestPrincipalProvider $principals;
    private AuthorizationTestScopes $scopes;
    private AuthorizationTestUser $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }
        foreach ([
            'workflow.instance.start', 'workflow.instance.view', 'workflow.definition.publish',
            'workflow.action.submit', 'workflow.action.resubmit', 'workflow.action.require_correction',
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findByName('agency', 'web')->givePermissionTo([
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit', 'workflow.action.resubmit',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'workflow.instance.view', 'workflow.action.require_correction',
        ]);
        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'workflow.definition.publish', 'workflow.instance.view',
        ]);
        $this->account = AuthorizationTestUser::create([
            'name' => 'Test Agency', 'email' => 'authz@example.test', 'password' => 'unused-test-password',
        ]);
        $this->principals = new AuthorizationTestPrincipalProvider();
        $this->scopes = new AuthorizationTestScopes();
        $this->scopes->owners = ['owned-1' => 'agency-a', 'foreign-1' => 'agency-b'];
        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->asRole('agency', 'agency-a');
    }

    private function asRole(string $role, ?string $organization): ActorContext
    {
        $this->account->syncRoles([$role]);
        Auth::guard('web')->setUser($this->account);
        $actor = new ActorContext((string) $this->account->getAuthIdentifier(), $role, $organization);
        $this->principals->principal = new Principal($this->account, $actor, 'web');
        return $actor;
    }

    private function authorize(ActorContext $actor, string $ability, string $id = 'owned-1'): void
    {
        $this->app->make(AuthorizerInterface::class)->authorize($actor, new AccessRequest($ability, 'sample.request', $id));
    }

    public function test_agency_can_view_own_request(): void
    {
        $this->authorize($this->principals->current()->actor, 'workflow.instance.view');
        $this->addToAssertionCount(1);
    }

    public function test_agency_cannot_view_foreign_request(): void
    {
        $this->expectException(AuthorizationDenied::class);
        $this->authorize($this->principals->current()->actor, 'workflow.instance.view', 'foreign-1');
    }

    public function test_government_admin_can_view_requests_for_its_single_service(): void
    {
        $actor = $this->asRole('government_admin', 'government');
        $this->authorize($actor, 'workflow.instance.view', 'foreign-1');
        $this->addToAssertionCount(1);
    }

    public function test_government_admin_cannot_publish_definition_without_permission(): void
    {
        $actor = $this->asRole('government_admin', 'government');
        $this->expectException(AuthorizationDenied::class);
        $this->authorize($actor, 'workflow.definition.publish', 'ncr.sample');
    }

    public function test_platform_admin_only_has_explicit_permissions(): void
    {
        $actor = $this->asRole('platform_admin', 'platform');
        $this->authorize($actor, 'workflow.definition.publish', 'ncr.sample');
        try {
            $this->authorize($actor, 'workflow.action.require_correction');
            $this->fail('Platform admin must not bypass missing permissions.');
        } catch (AuthorizationDenied $denied) {
            $this->assertSame('permission_denied', $denied->reasonCode);
        }
    }

    public function test_access_cannot_be_escalated_by_another_assigned_role(): void
    {
        $this->account->assignRole('platform_admin');
        $this->expectException(AuthorizationDenied::class);
        // User has publish via platform_admin, but selected principal remains agency.
        $this->authorize($this->principals->current()->actor, 'workflow.definition.publish', 'ncr.sample');
    }

    public function test_caller_cannot_spoof_role_or_agency(): void
    {
        $this->expectException(AuthorizationDenied::class);
        $this->authorize(new ActorContext((string) $this->account->getAuthIdentifier(), 'platform_admin', 'platform'), 'workflow.definition.publish');
    }

    public function test_actual_auth_guard_must_contain_principal(): void
    {
        Auth::guard('web')->logout();
        $this->expectException(AuthorizationDenied::class);
        $this->authorize($this->principals->current()->actor, 'workflow.instance.view');
    }

    public function test_scope_denial_prevents_action_even_with_permission(): void
    {
        $this->scopes->denyAll = true;
        $this->expectException(AuthorizationDenied::class);
        $this->authorize($this->principals->current()->actor, 'workflow.instance.view');
    }

    public function test_scope_exception_fails_closed(): void
    {
        $this->scopes->throw = true;
        $this->expectException(AuthorizationDenied::class);
        $this->authorize($this->principals->current()->actor, 'workflow.instance.view');
    }

    public function test_workflow_actor_bridge_uses_trusted_principal(): void
    {
        $resolved = $this->app->make(WorkflowActorResolver::class)->resolve();
        $this->assertSame((string) $this->account->getAuthIdentifier(), $resolved->actorId);
        $this->assertSame('agency', $resolved->role);
        $this->assertSame('agency-a', $resolved->organizationId);
    }

    public function test_workflow_authorization_bridge_maps_actions(): void
    {
        $bridge = $this->app->make(WorkflowAuthorizationBridge::class);
        $actor = new WorkflowActorContext((string) $this->account->getAuthIdentifier(), 'agency', 'agency-a');
        $instance = new WorkflowInstance('01TEST', 'sample.request', 'owned-1', 'sample', 1, 'draft', 0);
        $bridge->authorizeStart($this->definition(), 'sample.request', 'owned-1', $actor);
        $bridge->authorizeAction($instance, 'submit', $actor);
        try {
            $bridge->authorizeAction($instance, 'require_correction', $actor);
            $this->fail('Agency cannot call government action.');
        } catch (AuthorizationDenied $denied) {
            $this->assertSame('permission_denied', $denied->reasonCode);
        }
    }

    public function test_workflow_bridge_publish_requires_explicit_platform_permission(): void
    {
        $bridge = $this->app->make(WorkflowAuthorizationBridge::class);
        $platform = $this->asRole('platform_admin', 'platform');
        $bridge->authorizePublish($this->definition(), new WorkflowActorContext($platform->id, $platform->role, $platform->organizationId));
        $this->addToAssertionCount(1);
    }

    private function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'id' => 'sample', 'version' => 1, 'initial_state' => 'draft',
            'states' => ['draft', 'submitted'],
            'transitions' => [[
                'action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency'],
            ]],
        ]);
    }
}

final class AuthorizationTestUser extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class AuthorizationTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class AuthorizationTestScopes implements ScopeChecker
{
    /** @var array<string, string> */
    public array $owners = [];
    public bool $denyAll = false;
    public bool $throw = false;

    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($this->throw) {
            throw new \RuntimeException('Missing resource repository');
        }
        if ($this->denyAll) {
            return false;
        }
        if ($request->resourceType === 'workflow.definition') {
            return $actor->role === 'platform_admin';
        }
        if ($actor->role === 'agency') {
            return ($this->owners[$request->resourceId] ?? null) === $actor->organizationId;
        }
        // Each service has exactly one government organization; no inter-government filter.
        return in_array($actor->role, ['government_admin', 'platform_admin'], true);
    }
}
