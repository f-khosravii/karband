<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\AuthorizationLaravel\SpatieAuthorizer;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Enums\OrganizationRole;
use Karband\Directory\Exceptions\DirectoryActorResolutionException;
use Karband\DirectoryLaravel\Services\DirectoryResolver;
use Karband\DirectoryLaravel\Services\DirectoryStorage;
use Karband\ExternalAuth\Contracts\ExternalAuthProviderInterface;
use Karband\ExternalAuth\ValueObjects\ExternalIdentity;
use Karband\ExternalAuth\ValueObjects\ExternalSessionStatus;
use Karband\ExternalAuthLaravel\Contracts\ExternalPrincipalResolver;
use Karband\ExternalAuthLaravel\Services\ExternalAuthService;
use Karband\ExternalAuthLaravel\Services\ExternalSessionStorage;
use Karband\ExternalAuthLaravel\Testing\FakeEpishkhanV2Adapter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class SandboxTestUser extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guarded = [];
    protected string $guard_name = 'web';
}

final class DirectoryAwareExternalPrincipalResolver implements ExternalPrincipalResolver
{
    public function __construct(
        private DirectoryResolver $resolver,
        private DirectoryStorage $storage
    ) {}

    public function resolve(ExternalIdentity $identity): Principal
    {
        // If external identity has an organization ID and no subject or agency provider
        if (str_starts_with($identity->provider, 'epishkhan') && $identity->organizationId !== null && str_starts_with($identity->organizationId, 'AG-')) {
            // Agency organization reference
            $actor = $this->resolver->resolveBySourceReference(
                source: 'epishkhan',
                referenceKind: 'organization',
                externalId: $identity->organizationId
            );

            // Note: $identity->subjectId in Epishkhan agency context is the external member_id (e.g. M-7821)
            // It is NOT a Directory User. We do NOT create or assign a Directory User.
            $authUser = new SandboxTestUser([
                'id' => 9999, // Local session placeholder authenticatable
                'name' => 'Agency Service Actor',
                'email' => 'agency@external.gov',
            ]);

            return new Principal(
                user: $authUser,
                actor: new ActorContext(
                    id: $actor->organizationId,
                    role: 'agency',
                    organizationId: $actor->organizationId
                ),
                guard: 'web'
            );
        }

        // Operator or subject reference
        $actor = $this->resolver->resolveBySourceReference(
            source: $identity->provider,
            referenceKind: 'subject',
            externalId: $identity->subjectId,
            explicitOrganizationId: $identity->organizationId
        );

        $authUser = SandboxTestUser::firstOrCreate(
            ['id' => 10],
            ['name' => 'Operator User', 'email' => 'operator@platform.gov']
        );

        return new Principal(
            user: $authUser,
            actor: new ActorContext(
                id: (string) $actor->userId,
                role: 'platform_admin',
                organizationId: $actor->organizationId
            ),
            guard: 'web'
        );
    }
}

final class DirectoryScenarioTest extends TestCase
{
    use RefreshDatabase;

    private DirectoryStorage $storage;
    private DirectoryResolver $resolver;
    private SpatieAuthorizer $authorizer;
    private PrincipalProvider $principalsProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->storage = $this->app->make(DirectoryStorage::class);
        $this->resolver = $this->app->make(DirectoryResolver::class);

        // Set up Spatie permissions and roles
        Role::findOrCreate('platform_admin', 'web');
        Role::findOrCreate('agency', 'web');
        Role::findOrCreate('provider', 'web');

        Permission::findOrCreate('service.request.create', 'web');
        Permission::findOrCreate('platform.settings.manage', 'web');

        $roleAdmin = Role::findByName('platform_admin', 'web');
        $roleAdmin->givePermissionTo('platform.settings.manage');

        $roleAgency = Role::findByName('agency', 'web');
        $roleAgency->givePermissionTo('service.request.create');

        $principals = new class implements PrincipalProvider {
            public ?Principal $current = null;
            public function current(): Principal
            {
                return $this->current ?? throw new \RuntimeException('No principal set');
            }
        };

        $scopes = new class implements ScopeChecker {
            public function allows(ActorContext $actor, AccessRequest $request): bool
            {
                return true;
            }
        };

        $this->principalsProvider = $principals;
        $this->authorizer = new SpatieAuthorizer($principals, $scopes);
    }

    /**
     * Scenario A — Operator dual-auth identity:
     * Same Directory User and Organization through local and external auth, no duplicate profile.
     */
    public function test_scenario_a_operator_dual_auth_identity(): void
    {
        // 1. Provision Operator Org and Operator User in Directory
        $org = $this->storage->createOrganization('ORG-001', 'INTL Operator Org', DirectoryStatus::Active, [OrganizationRole::Operator]);
        $user = $this->storage->createUser('U-10', 'Ahmad Operator', DirectoryStatus::Active, 'ahmad@platform.gov');
        $this->storage->createMembership('MEM-001', 'U-10', 'ORG-001', DirectoryStatus::Active);

        // Link Epishkhan external subject to same Directory User U-10
        $this->storage->linkUserReference('epishkhan', 'subject', 'EXT-SUBJ-99', 'U-10');

        // Path 1: Local Auth resolution
        // Local auth has local user U-10 and explicit organization ORG-001
        $localActor = $this->resolver->resolveUserActor('U-10', 'ORG-001');
        $this->assertSame('ORG-001', $localActor->organizationId);
        $this->assertSame('U-10', $localActor->userId);

        // Path 2: External Auth resolution (via Epishkhan ticket)
        $externalActor = $this->resolver->resolveBySourceReference(
            source: 'epishkhan',
            referenceKind: 'subject',
            externalId: 'EXT-SUBJ-99',
            explicitOrganizationId: 'ORG-001'
        );
        $this->assertSame('ORG-001', $externalActor->organizationId);
        $this->assertSame('U-10', $externalActor->userId);

        // Verification: Exactly same Directory User and Organization, single Directory record
        $this->assertSame($localActor->userId, $externalActor->userId);
        $this->assertSame($localActor->organizationId, $externalActor->organizationId);
        $this->assertCount(1, $this->storage->listUserMemberships('U-10'));
    }

    /**
     * Scenario B — Agency:
     * External organization reference -> agency Organization, User null, member_id attribution only,
     * no Directory User auto-created.
     */
    public function test_scenario_b_agency_member_id_attribution_only(): void
    {
        // 1. Provision Agency in Directory
        $this->storage->createOrganization('ORG-500', 'Tehran Central Agency', DirectoryStatus::Active, [OrganizationRole::Agency]);
        $this->storage->linkOrganizationReference('epishkhan', 'organization', 'AG-728', 'ORG-500');

        // 2. Incoming Epishkhan authenticated session with member_id = M-7821
        $adapter = new FakeEpishkhanV2Adapter();
        $adapter->stageTicket(
            ticket: 'valid-agency-ticket',
            subjectId: 'M-7821', // External agency member ID
            organizationId: 'AG-728', // Central agency ID
            sessionId: 'sess-ag-1',
            issuedAt: new \DateTimeImmutable('-1 minute'),
            expiresAt: new \DateTimeImmutable('+1 hour'),
            displayClaims: ['agency_name' => 'Tehran Central Agency']
        );

        $resolver = new DirectoryAwareExternalPrincipalResolver($this->resolver, $this->storage);
        $extAuthService = new ExternalAuthService(
            provider: $adapter,
            resolver: $resolver,
            storage: new ExternalSessionStorage($this->app['db'])
        );

        $principal = $extAuthService->authenticateTicket('valid-agency-ticket');

        // Assertions:
        // Resolved organization is ORG-500
        $this->assertSame('ORG-500', $principal->actor->organizationId);
        $this->assertSame('agency', $principal->actor->role);

        // Assert NO Directory User was created for M-7821
        $this->assertNull($this->storage->findUser('M-7821'));
        $this->assertNull($this->storage->findExternalReference('epishkhan', 'subject', 'M-7821'));
    }

    /**
     * Scenario C — Provider:
     * Provider Organization resolves without User.
     */
    public function test_scenario_c_provider_resolves_without_user(): void
    {
        $this->storage->createOrganization('ORG-200', 'Ministry of Justice', DirectoryStatus::Active, [OrganizationRole::Provider]);
        $this->storage->linkOrganizationReference('sso-gov', 'organization', 'GOV-JUSTICE', 'ORG-200');

        $actor = $this->resolver->resolveBySourceReference('sso-gov', 'organization', 'GOV-JUSTICE');

        $this->assertSame('ORG-200', $actor->organizationId);
        $this->assertNull($actor->userId);
        $this->assertTrue($actor->isOrganizationOnlyActor());
        $this->assertFalse($actor->isUserActor());
    }

    /**
     * Scenario D — Lifecycle:
     * Inactive Organization/User/Membership and unknown mapping deny.
     */
    public function test_scenario_d_lifecycle_denials_fail_closed(): void
    {
        // 1. Inactive Organization
        $this->storage->createOrganization('ORG-INACTIVE', 'Closed Org', DirectoryStatus::Inactive, [OrganizationRole::Provider]);
        $this->storage->linkOrganizationReference('sso', 'organization', 'EXT-INACTIVE-ORG', 'ORG-INACTIVE');

        try {
            $this->resolver->resolveBySourceReference('sso', 'organization', 'EXT-INACTIVE-ORG');
            $this->fail('Expected DirectoryActorResolutionException for inactive organization');
        } catch (DirectoryActorResolutionException $e) {
            $this->assertStringContainsString('missing or inactive', $e->getMessage());
        }

        // 2. Inactive User
        $this->storage->createOrganization('ORG-ACT', 'Active Org', DirectoryStatus::Active);
        $this->storage->createUser('USR-INACTIVE', 'Suspended User', DirectoryStatus::Inactive);
        $this->storage->createMembership('MEM-I1', 'USR-INACTIVE', 'ORG-ACT', DirectoryStatus::Active);
        $this->storage->linkUserReference('sso', 'subject', 'EXT-INACTIVE-USER', 'USR-INACTIVE');

        try {
            $this->resolver->resolveBySourceReference('sso', 'subject', 'EXT-INACTIVE-USER', 'ORG-ACT');
            $this->fail('Expected DirectoryActorResolutionException for inactive user');
        } catch (DirectoryActorResolutionException $e) {
            $this->assertStringContainsString('missing or inactive', $e->getMessage());
        }

        // 3. Inactive Membership
        $this->storage->createUser('USR-ACT', 'Active User', DirectoryStatus::Active);
        $this->storage->createMembership('MEM-I2', 'USR-ACT', 'ORG-ACT', DirectoryStatus::Inactive);
        $this->storage->linkUserReference('sso', 'subject', 'EXT-ACT-USER', 'USR-ACT');

        try {
            $this->resolver->resolveBySourceReference('sso', 'subject', 'EXT-ACT-USER', 'ORG-ACT');
            $this->fail('Expected DirectoryActorResolutionException for inactive membership');
        } catch (DirectoryActorResolutionException $e) {
            $this->assertStringContainsString('missing or inactive', $e->getMessage());
        }

        // 4. Unknown mapping
        try {
            $this->resolver->resolveBySourceReference('sso', 'subject', 'UNKNOWN-EXTERNAL-ID', 'ORG-ACT');
            $this->fail('Expected DirectoryActorResolutionException for unknown mapping');
        } catch (DirectoryActorResolutionException $e) {
            $this->assertStringContainsString('Unknown source reference', $e->getMessage());
        }
    }

    /**
     * Scenario E — Multi-membership:
     * One User, two active Memberships; no org context denied; explicit A/B resolves correctly.
     */
    public function test_scenario_e_multi_membership_handling(): void
    {
        $this->storage->createOrganization('ORG-A', 'First Org', DirectoryStatus::Active);
        $this->storage->createOrganization('ORG-B', 'Second Org', DirectoryStatus::Active);
        $this->storage->createOrganization('ORG-C', 'Third Org (No Membership)', DirectoryStatus::Active);

        $this->storage->createUser('USR-MULTI', 'Consultant User', DirectoryStatus::Active);
        $this->storage->createMembership('M-A', 'USR-MULTI', 'ORG-A', DirectoryStatus::Active);
        $this->storage->createMembership('M-B', 'USR-MULTI', 'ORG-B', DirectoryStatus::Active);
        $this->storage->linkUserReference('sso', 'subject', 'EXT-CONSULTANT', 'USR-MULTI');

        // No org context -> DENIED (must never pick first record)
        try {
            $this->resolver->resolveBySourceReference('sso', 'subject', 'EXT-CONSULTANT', null);
            $this->fail('Expected DirectoryActorResolutionException for omitted organization context');
        } catch (DirectoryActorResolutionException $e) {
            $this->assertStringContainsString('explicit organization context', $e->getMessage());
        }

        // Explicit ORG-A -> Resolves to ORG-A + USR-MULTI
        $actorA = $this->resolver->resolveBySourceReference('sso', 'subject', 'EXT-CONSULTANT', 'ORG-A');
        $this->assertSame('ORG-A', $actorA->organizationId);
        $this->assertSame('USR-MULTI', $actorA->userId);

        // Explicit ORG-B -> Resolves to ORG-B + USR-MULTI
        $actorB = $this->resolver->resolveBySourceReference('sso', 'subject', 'EXT-CONSULTANT', 'ORG-B');
        $this->assertSame('ORG-B', $actorB->organizationId);
        $this->assertSame('USR-MULTI', $actorB->userId);

        // Explicit ORG-C (not a member) -> DENIED
        try {
            $this->resolver->resolveBySourceReference('sso', 'subject', 'EXT-CONSULTANT', 'ORG-C');
            $this->fail('Expected DirectoryActorResolutionException for non-member organization');
        } catch (DirectoryActorResolutionException $e) {
            $this->assertStringContainsString('missing or inactive', $e->getMessage());
        }
    }

    /**
     * Scenario F — Domain role vs Authorization:
     * OrganizationRole and external role claims grant no local permissions by themselves.
     */
    public function test_scenario_f_domain_role_does_not_grant_authorization_permissions(): void
    {
        // OrganizationRole::OPERATOR does not grant Spatie permissions
        $org = $this->storage->createOrganization('ORG-OP', 'Operator Org', DirectoryStatus::Active, [OrganizationRole::Operator]);
        $user = $this->storage->createUser('USR-PLAIN', 'Plain Operator User', DirectoryStatus::Active);
        $this->storage->createMembership('MEM-OP', 'USR-PLAIN', 'ORG-OP', DirectoryStatus::Active);

        $authUser = new SandboxTestUser([
            'id' => 888,
            'name' => 'Plain User',
            'email' => 'plain@platform.gov',
        ]);
        // Note: $authUser does NOT have the 'platform_admin' role or 'platform.settings.manage' permission

        $principal = new Principal(
            user: $authUser,
            actor: new ActorContext(id: 'USR-PLAIN', role: 'plain_operator', organizationId: 'ORG-OP'),
            guard: 'web'
        );

        \Illuminate\Support\Facades\Auth::guard('web')->setUser($authUser);
        $this->principalsProvider->current = $principal;

        // Authorizer denies access despite Organization having OrganizationRole::OPERATOR
        $this->expectException(AuthorizationDenied::class);
        $this->authorizer->authorize(
            $principal->actor,
            new AccessRequest('platform.settings.manage', 'settings', 'global')
        );
    }
}
