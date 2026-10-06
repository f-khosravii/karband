<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Intl\AuditLaravel\Exceptions\AuditRecordDenied;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Principal;
use Intl\Contracts\Audit\AuditEvent;
use Intl\Contracts\Audit\AuditRecorderInterface;
use Intl\Contracts\Authorization\ActorContext;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

/** Requires Spatie Activitylog migration in THIS sandbox test database only. */
final class IntlAuditTest extends TestCase
{
    use RefreshDatabase;

    private AuditTestUser $account;
    private AuditTestPrincipals $principals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('agency', 'web');
        Role::findOrCreate('government_admin', 'web');
        $this->account = AuditTestUser::create([
            'name' => 'Audit Test', 'email' => 'intl-audit@example.test', 'password' => 'unused-test-password',
        ]);
        $this->account->assignRole('agency');
        Auth::guard('web')->setUser($this->account);
        $this->principals = new AuditTestPrincipals();
        $this->principals->principal = new Principal(
            $this->account,
            new ActorContext((string) $this->account->getAuthIdentifier(), 'agency', 'agency-a'),
            'web',
        );
        $this->app->instance(PrincipalProvider::class, $this->principals);
    }

    private function recorder(): AuditRecorderInterface
    {
        return $this->app->make(AuditRecorderInterface::class);
    }

    private function sample(): AuditEvent
    {
        return new AuditEvent('workflow.transition.completed', 'request', 'req-123', [
            'from_state' => 'submitted', 'to_state' => 'approved', 'correlation_id' => 'c-123',
        ]);
    }

    public function test_trusted_agency_action_is_recorded_locally_and_attributed(): void
    {
        $this->recorder()->record($this->sample());
        $activity = Activity::query()->where('log_name', 'intl-audit')->firstOrFail();
        $this->assertSame('workflow.transition.completed', $activity->event);
        $this->assertSame('agency', $activity->properties->get('actor_role'));
        $this->assertSame('agency-a', $activity->properties->get('actor_organization_id'));
        $this->assertSame('req-123', $activity->properties->get('subject_id'));
        $this->assertSame('c-123', $activity->properties->get('metadata')['correlation_id']);
        $this->assertSame((string) $this->account->getAuthIdentifier(), (string) $activity->causer_id);
    }

    public function test_a_second_assigned_role_cannot_relabel_selected_actor(): void
    {
        $this->account->assignRole('government_admin');
        $this->recorder()->record($this->sample());
        $this->assertSame('agency', Activity::query()->firstOrFail()->properties->get('actor_role'));
    }

    public function test_actor_role_that_is_not_assigned_is_rejected_without_logging(): void
    {
        $this->principals->principal = new Principal(
            $this->account,
            new ActorContext((string) $this->account->getAuthIdentifier(), 'government_admin', 'government'),
            'web',
        );
        $this->expectException(AuditRecordDenied::class);
        try {
            $this->recorder()->record($this->sample());
        } finally {
            $this->assertSame(0, Activity::query()->count());
        }
    }

    public function test_actor_id_cannot_be_spoofed(): void
    {
        $this->principals->principal = new Principal(
            $this->account,
            new ActorContext('some-other-user', 'agency', 'agency-a'),
            'web',
        );
        $this->expectException(AuditRecordDenied::class);
        $this->recorder()->record($this->sample());
    }

    public function test_anonymous_user_cannot_be_attributed_to_previous_principal(): void
    {
        Auth::guard('web')->logout();
        $this->expectException(AuditRecordDenied::class);
        $this->recorder()->record($this->sample());
    }

    public function test_missing_trusted_principal_fails_closed(): void
    {
        $this->principals->principal = null;
        $this->expectException(AuditRecordDenied::class);
        $this->recorder()->record($this->sample());
    }

    public function test_unregistered_event_cannot_be_logged(): void
    {
        $this->expectException(AuditRecordDenied::class);
        $this->recorder()->record(new AuditEvent('unsupported.internal.action', 'request', 'req-123'));
    }

    public function test_success_audit_is_rolled_back_with_local_business_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                $this->recorder()->record($this->sample());
                $this->assertSame(1, Activity::query()->count());
                throw new \RuntimeException('Roll back business transaction.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Roll back business transaction.', $exception->getMessage());
        }
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_payment_outcome_can_be_recorded_without_wallet_details(): void
    {
        $this->recorder()->record(new AuditEvent('billing.payment.unknown', 'request', 'req-123', [
            'operation_id' => 'opaque-op-7', 'result' => 'unknown',
        ]));
        $properties = Activity::query()->firstOrFail()->properties->toArray();
        $this->assertSame('opaque-op-7', $properties['metadata']['operation_id']);
        $this->assertArrayNotHasKey('wallet', $properties);
        $this->assertArrayNotHasKey('token', $properties);
    }
}

final class AuditTestPrincipals implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('No authenticated principal.');
    }
}

final class AuditTestUser extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}
