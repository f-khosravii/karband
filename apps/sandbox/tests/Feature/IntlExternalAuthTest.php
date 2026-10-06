<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Contracts\ScopeChecker;
use Intl\AuthorizationLaravel\Principal;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\ExternalAuth\Contracts\ExternalAuthProviderInterface;
use Intl\ExternalAuth\ValueObjects\ExternalIdentity;
use Intl\ExternalAuthLaravel\Contracts\ExternalPrincipalResolver;
use Intl\ExternalAuthLaravel\Services\ExternalAuthService;
use Intl\ExternalAuthLaravel\Testing\FakeEpishkhanV2Adapter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class IntlExternalAuthTest extends TestCase
{
    use RefreshDatabase;

    private FakeEpishkhanV2Adapter $adapter;
    private SandboxExternalAuthResolver $resolver;
    private ExternalAuthService $externalAuthService;
    private SandboxTestPrincipalProvider $principals;
    private SandboxTestScopes $scopes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // Roles in local microservice
        foreach (['agency', 'government_admin', 'platform_admin'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        // Permissions in local microservice
        foreach (['service.request.submit', 'service.request.approve', 'service.request.view'] as $permName) {
            Permission::findOrCreate($permName, 'web');
        }

        Role::findByName('agency', 'web')->givePermissionTo(['service.request.submit', 'service.request.view']);
        Role::findByName('government_admin', 'web')->givePermissionTo(['service.request.approve', 'service.request.view']);

        $this->adapter = new FakeEpishkhanV2Adapter();
        $this->app->instance(ExternalAuthProviderInterface::class, $this->adapter);

        $this->resolver = new SandboxExternalAuthResolver();
        $this->app->instance(ExternalPrincipalResolver::class, $this->resolver);

        $this->principals = new SandboxTestPrincipalProvider();
        $this->app->instance(PrincipalProvider::class, $this->principals);

        $this->scopes = new SandboxTestScopes();
        $this->app->instance(ScopeChecker::class, $this->scopes);

        $this->externalAuthService = $this->app->make(ExternalAuthService::class);
    }

    public function test_external_auth_pipeline_to_local_authorization(): void
    {
        // 1. Central Epishkhan V2 SSO handoff ticket exchange
        $issued = new \DateTimeImmutable('now');
        $expires = new \DateTimeImmutable('+1 hour');

        $this->adapter->stageTicket(
            ticket: 'sso-ticket-123',
            subjectId: 'ext-user-777',
            organizationId: 'agency-ncr',
            sessionId: 'epishkhan-session-999',
            issuedAt: $issued,
            expiresAt: $expires,
            displayClaims: ['name' => 'NCR Applicant', 'sso_claimed_role' => 'government_admin'] // SSO claims role 'government_admin'
        );

        // 2. Exchange ticket via ExternalAuthService
        $principal = $this->externalAuthService->authenticateTicket('sso-ticket-123');

        // Set current principal & user session
        $this->principals->principal = $principal;
        Auth::guard('web')->setUser($principal->user);

        // 3. Verify identity pipeline
        self::assertSame('user-ext-user-777', $principal->actor->id);
        self::assertSame('agency', $principal->actor->role); // Resolver mapped local role 'agency', ignoring SSO claim
        self::assertSame('agency-ncr', $principal->actor->organizationId);

        // 4. Test permitted local operation
        $authorizer = $this->app->make(AuthorizerInterface::class);
        $authorizer->authorize(
            $principal->actor,
            new AccessRequest('service.request.submit', 'service_request', 'req-001')
        );
        $this->addToAssertionCount(1);
    }

    public function test_external_identity_claiming_sso_role_does_not_grant_unauthorized_local_permission(): void
    {
        // Central Epishkhan V2 SSO handoff claims platform_admin / government_admin in claims
        $this->adapter->stageTicket(
            ticket: 'sso-ticket-malicious',
            subjectId: 'ext-user-888',
            organizationId: 'agency-ncr',
            sessionId: 'epishkhan-session-888',
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour'),
            displayClaims: ['sso_role' => 'government_admin', 'sso_permission' => 'service.request.approve']
        );

        $principal = $this->externalAuthService->authenticateTicket('sso-ticket-malicious');
        $this->principals->principal = $principal;
        Auth::guard('web')->setUser($principal->user);

        // The user only has local role 'agency' as mapped by local resolver
        $authorizer = $this->app->make(AuthorizerInterface::class);

        // Agency cannot approve requests (government_admin only)
        $this->expectException(AuthorizationDenied::class);
        $authorizer->authorize(
            $principal->actor,
            new AccessRequest('service.request.approve', 'service_request', 'req-001')
        );
    }
}

/** User model for sandbox integration test */
class SandboxAuthUser extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
    public $timestamps = false;
}

/** Microservice authoritative resolver mapping external identity to local user & roles */
final class SandboxExternalAuthResolver implements ExternalPrincipalResolver
{
    public function resolve(ExternalIdentity $identity): Principal
    {
        // Look up or provision local user
        $user = SandboxAuthUser::firstOrCreate(
            ['email' => 'user-' . $identity->subjectId . '@local.service'],
            ['name' => $identity->displayClaims['name'] ?? 'External User', 'password' => 'unused']
        );

        // Microservice maps local roles authoritatively.
        // Even if external identity claims a role, local policy enforces 'agency'.
        $user->syncRoles(['agency']);

        $actor = new ActorContext(
            id: 'user-' . $identity->subjectId,
            role: 'agency',
            organizationId: $identity->organizationId
        );

        return new Principal($user, $actor, 'web');
    }
}

final class SandboxTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;
    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('No principal set.');
    }
}

final class SandboxTestScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        return true;
    }
}
