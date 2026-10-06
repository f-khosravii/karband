<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Billing\ValueObjects\Money;
use Karband\Billing\Refund\RefundInitiation;
use Karband\Billing\Refund\RefundPolicy;
use Karband\Billing\Refund\RefundOutcome;
use Karband\Billing\Refund\RefundStatus;
use Karband\BillingLaravel\Contracts\TrustedRefundPolicyProvider;
use Karband\BillingLaravel\Contracts\TrustedRefundEventVerifier;
use Karband\BillingLaravel\Contracts\CentralRefundGateway;
use Karband\BillingLaravel\Services\RefundService;
use Karband\Billing\Refund\RefundCommand;
use Karband\Billing\ValueObjects\PaymentCommand;
use Karband\Billing\ValueObjects\PaymentOutcome;
use Karband\Billing\ValueObjects\PaymentStatus;
use Karband\Billing\ValueObjects\PreparePaymentCommand;
use Karband\Billing\ValueObjects\PreparePaymentOutcome;
use Karband\Billing\ValueObjects\ConfirmPaymentCommand;
use Karband\Billing\ValueObjects\ConfirmPaymentOutcome;
use Karband\Billing\ValueObjects\CancelPaymentCommand;
use Karband\Billing\ValueObjects\CancelPaymentOutcome;
use Karband\BillingLaravel\Contracts\CentralPaymentGateway;
use Karband\BillingLaravel\Contracts\TrustedFinancialTermsProvider;
use Karband\BillingLaravel\Contracts\TrustedQuoteProvider;
use Karband\BillingLaravel\Exceptions\PaymentConflict;
use Karband\BillingLaravel\Services\PaymentService;
use Karband\BillingLaravel\Services\TrustedPaymentFacts;
use Karband\BillingLaravel\ValueObjects\FinancialTermsSnapshot;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

/** Isolated SQLite Sandbox only. Fake central transport deliberately contains no wallet implementation. */
final class BillingCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private BillingFixturePrincipalProvider $principals;
    private BillingFixtureScopes $scopes;
    private BillingFixtureQuotes $quotes;
    private BillingFixtureCentral $central;
    private BillingFixtureRefundPolicies $refundPolicies;
    private BillingFixtureRefundVerifier $refundVerifier;
    private BillingFixtureRefundCentral $refundCentral;
    private BillingFixtureUser $account;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.client_namespace', 'sandbox-service-A');
        config()->set('billing.reconcile_after_seconds', 0);
        config()->set('billing.max_attempts', 5);
        config()->set('billing.quote_ttl_seconds', 900);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        foreach ([
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.retry',
            'billing.payment.reconcile', 'billing.payment.view',
            'billing.refund.propose', 'billing.refund.view', 'billing.refund.approve',
            'billing.refund.execute', 'billing.refund.reconcile',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findByName('agency', 'web')->givePermissionTo([
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.retry',
            'billing.payment.reconcile', 'billing.payment.view',
            'billing.refund.propose', 'billing.refund.view',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'billing.payment.view', 'billing.refund.propose', 'billing.refund.view',
            'billing.refund.approve', 'billing.refund.execute', 'billing.refund.reconcile',
        ]);
        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'billing.payment.view', 'billing.payment.reconcile',
            'billing.refund.view', 'billing.refund.reconcile',
        ]);

        $this->account = BillingFixtureUser::create([
            'name' => 'Billing test', 'email' => 'billing-test@example.test', 'password' => 'test-only',
        ]);
        $this->principals = new BillingFixturePrincipalProvider();
        $this->scopes = new BillingFixtureScopes();
        $this->quotes = new BillingFixtureQuotes();
        $this->central = new BillingFixtureCentral();
        $this->refundPolicies = new BillingFixtureRefundPolicies();
        $this->refundVerifier = new BillingFixtureRefundVerifier();
        $this->refundCentral = new BillingFixtureRefundCentral();
        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->app->instance(TrustedQuoteProvider::class, $this->quotes);
        $this->app->instance(CentralPaymentGateway::class, $this->central);
        $this->app->instance(TrustedRefundPolicyProvider::class, $this->refundPolicies);
        $this->app->instance(TrustedFinancialTermsProvider::class, new class($this->quotes, $this->refundPolicies) implements TrustedFinancialTermsProvider {
            public function __construct(private BillingFixtureQuotes $quotes, private BillingFixtureRefundPolicies $refundPolicies) {}
            public function snapshot(string $s, string $rt, string $ri, string $a): FinancialTermsSnapshot {
                return new FinancialTermsSnapshot($this->quotes->quote($s, $rt, $ri, $a), $this->refundPolicies->forPayment($s, $rt, $ri, $a), 1);
            }
        });
        $this->app->instance(TrustedRefundEventVerifier::class, $this->refundVerifier);
        $this->app->instance(CentralRefundGateway::class, $this->refundCentral);
        $this->asRole('agency', 'agency-1');
    }

    private function asRole(string $role, ?string $organization): void
    {
        $this->account->syncRoles([$role]);
        Auth::guard('web')->setUser($this->account);
        $this->principals->principal = new Principal($this->account,
            new ActorContext((string) $this->account->id, $role, $organization), 'web');
    }

    private function service(): PaymentService { return $this->app->make(PaymentService::class); }
    private function refunds(): RefundService { return $this->app->make(RefundService::class); }
    private function dispatch(string $id) {
        $quote = $this->service()->view($id);
        return $this->service()->dispatch($id, $quote->money->amount, $quote->money->currency, $quote->revision);
    }

    private function create(string $resource = 'r1', string $key = 'browser-1')
    {
        return $this->service()->create('sample', 'billing.request', $resource, $key);
    }

    public function test_creates_local_intent_with_trusted_price_and_no_central_call(): void
    {
        $created = $this->create();
        self::assertSame(500, $created->money->amount);
        self::assertSame('IRR', $created->money->currency);
        self::assertSame(PaymentStatus::Ready, $created->status);
        self::assertSame(0, $this->central->chargeCount);
        $this->assertDatabaseCount('payment_intents', 1);
    }

    public function test_same_key_and_resource_replays_local_creation(): void
    {
        $one = $this->create();
        $two = $this->create();
        self::assertSame($one->id, $two->id);
        $this->assertDatabaseCount('payment_intents', 1);
    }

    public function test_key_reuse_for_different_resource_is_denied(): void
    {
        $this->create();
        $this->expectException(PaymentConflict::class);
        $this->create('r2', 'browser-1');
    }

    public function test_second_key_for_same_resource_is_denied(): void
    {
        $this->create();
        $this->expectException(PaymentConflict::class);
        $this->create('r1', 'different-key');
    }

    public function test_agency_cannot_create_for_foreign_resource(): void
    {
        $this->expectException(AuthorizationDenied::class);
        $this->create('foreign-r', 'foreign-key');
    }

    public function test_government_admin_cannot_create_even_when_given_permission(): void
    {
        Role::findByName('government_admin', 'web')->givePermissionTo('billing.payment.create');
        $this->asRole('government_admin', 'government');
        $this->expectException(AuthorizationDenied::class);
        $this->create();
    }

    public function test_dispatch_success_creates_confirmed_fact(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::succeeded('central-0001');
        $done = $this->dispatch($record->id);
        self::assertSame(PaymentStatus::Succeeded, $done->status);
        self::assertSame('central-0001', $done->centralReference);
        self::assertTrue($this->app->make(TrustedPaymentFacts::class)->isConfirmed('sample', 'billing.request', 'r1'));
        self::assertSame(1, $this->central->chargeCount);
        self::assertCount(3, $this->service()->history($record->id)); // created, claimed, result
    }

    public function test_repeat_dispatch_does_not_call_central_again(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::succeeded('central-1');
        $this->dispatch($record->id);
        try {
            $this->dispatch($record->id);
            $this->fail('Second dispatch should not reach the provider.');
        } catch (PaymentConflict) {
            self::assertSame(1, $this->central->chargeCount);
        }
    }

    public function test_lost_response_becomes_unknown_then_lookup_recovers_without_second_debit(): void
    {
        $record = $this->create();
        $this->central->remoteSuccessThenTimeout = true;
        $unknown = $this->dispatch($record->id);
        self::assertSame(PaymentStatus::Unknown, $unknown->status);
        self::assertFalse($this->app->make(TrustedPaymentFacts::class)->isConfirmed('sample', 'billing.request', 'r1'));
        $recovered = $this->service()->reconcile($record->id);
        self::assertSame(PaymentStatus::Succeeded, $recovered->status);
        self::assertSame(1, $this->central->chargeCount);
        self::assertSame(1, $this->central->lookupCount);
    }

    public function test_ambiguous_lookup_cannot_recharge_or_settle(): void
    {
        $record = $this->create();
        $this->central->throwOnCharge = true;
        $unknown = $this->dispatch($record->id);
        self::assertSame(PaymentStatus::Unknown, $unknown->status);
        $again = $this->service()->reconcile($record->id);
        self::assertSame(PaymentStatus::Unknown, $again->status);
        self::assertSame(1, $this->central->chargeCount);
        self::assertFalse($this->app->make(TrustedPaymentFacts::class)->isConfirmed('sample', 'billing.request', 'r1'));
    }

    public function test_pending_reconciles_to_confirmed(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::pending('pending-ref');
        self::assertSame(PaymentStatus::Pending, $this->dispatch($record->id)->status);
        $this->central->lookupOutcome = PaymentOutcome::succeeded('pending-ref');
        self::assertSame(PaymentStatus::Succeeded, $this->service()->reconcile($record->id)->status);
    }

    public function test_definitive_no_debit_can_retry_with_distinct_central_key(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::failed('failed-ref');
        self::assertSame(PaymentStatus::Failed, $this->dispatch($record->id)->status);
        $new = $this->service()->retryFailed($record->id, 'retry-browser-2');
        self::assertSame(2, $new->attempt);
        self::assertSame('failed-ref', $this->service()->history($record->id)[3]->central_reference);
        self::assertSame(PaymentStatus::Ready, $new->status);
        $this->central->chargeOutcome = PaymentOutcome::succeeded('success-ref');
        self::assertSame(PaymentStatus::Succeeded, $this->dispatch($record->id)->status);
        self::assertCount(2, array_unique($this->central->chargeKeys));
    }

    public function test_cannot_retry_unknown_outcome(): void
    {
        $record = $this->create();
        $this->central->throwOnCharge = true;
        $this->dispatch($record->id);
        $this->expectException(PaymentConflict::class);
        $this->service()->retryFailed($record->id, 'retry-browser-2');
    }

    public function test_cannot_charge_if_authoritative_quote_rejects_new_state(): void
    {
        $record = $this->create();
        $this->quotes->payable = false;
        try {
            $this->dispatch($record->id);
            $this->fail('Provider must block before a central call.');
        } catch (\RuntimeException) {
            self::assertSame(PaymentStatus::Ready, $this->service()->view($record->id)->status);
            self::assertSame(0, $this->central->chargeCount);
        }
    }

    public function test_existing_payment_attempt_keeps_confirmed_price_when_catalog_price_changes(): void
    {
        $record = $this->create();
        $this->quotes->money = new Money(777, 'IRR');
        $this->central->chargeOutcome = PaymentOutcome::succeeded('unchanged-price-reference');
        $done = $this->dispatch($record->id);
        self::assertSame(500, $done->money->amount);
        self::assertSame([500], $this->central->chargeAmounts);
    }

    public function test_foreign_agency_cannot_view_payment(): void
    {
        $record = $this->create();
        $this->asRole('agency', 'agency-2');
        $this->expectException(AuthorizationDenied::class);
        $this->service()->view($record->id);
    }

    public function test_platform_admin_only_uses_explicit_reconcile_permission(): void
    {
        $record = $this->create();
        $this->central->throwOnCharge = true;
        $this->dispatch($record->id);
        $this->asRole('platform_admin', 'platform');
        self::assertSame(PaymentStatus::Unknown, $this->service()->reconcile($record->id)->status);
        $this->expectException(AuthorizationDenied::class);
        $this->dispatch($record->id);
    }

    public function test_processing_attempt_is_not_reconciled_prematurely(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::pending('ref-p');
        // Simulate a provider call still in progress by changing the local status within fixture DB.
        $this->dispatch($record->id);
        \Illuminate\Support\Facades\DB::table('payment_intents')->where('id', $record->id)
            ->update(['status' => PaymentStatus::Processing->value, 'dispatched_at' => now()]);
        config()->set('billing.reconcile_after_seconds', 600);
        try {
            $this->service()->reconcile($record->id);
            $this->fail('Recent processing attempt must not be queried.');
        } catch (PaymentConflict) {
            self::assertSame(0, $this->central->lookupCount);
        }
    }

    public function test_central_reference_mismatch_requires_manual_resolution(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::pending('one-reference');
        $this->dispatch($record->id);
        $this->central->lookupOutcome = PaymentOutcome::succeeded('different-reference');
        try {
            $this->service()->reconcile($record->id);
            $this->fail('Conflicting central identifiers must not be silently accepted.');
        } catch (PaymentConflict) {
            self::assertSame(PaymentStatus::Pending, $this->service()->view($record->id)->status);
        }
    }

    public function test_anonymous_request_denied(): void
    {
        $this->principals->principal = null;
        Auth::guard('web')->logout();
        $this->expectException(AuthorizationDenied::class);
        $this->create();
    }

    public function test_scope_exception_fails_closed(): void
    {
        $this->scopes->throw = true;
        $this->expectException(AuthorizationDenied::class);
        $this->create();
    }

    public function test_invalid_namespace_fails_without_write(): void
    {
        config()->set('billing.client_namespace', '');
        try { $this->create(); $this->fail('Namespace must be explicit.'); }
        catch (\RuntimeException) { $this->assertDatabaseCount('payment_intents', 0); }
    }

    public function test_stale_or_mismatched_price_confirmation_never_calls_provider(): void
    {
        $record = $this->create();
        try {
            $this->service()->dispatch($record->id, 777, 'IRR', $record->revision);
            $this->fail('Client must confirm exact current attempt price.');
        } catch (PaymentConflict) {
            self::assertSame(0, $this->central->chargeCount);
            self::assertSame(PaymentStatus::Ready, $this->service()->view($record->id)->status);
        }
    }

    public function test_expired_quote_can_be_refreshed_at_new_price(): void
    {
        $record = $this->create();
        \Illuminate\Support\Facades\DB::table('payment_attempts')
            ->where('payment_id', $record->id)->update(['created_at' => now()->subHours(2)]);
        try {
            $this->dispatch($record->id);
            $this->fail('Expired prices must not be charged.');
        } catch (PaymentConflict) {
            self::assertSame(0, $this->central->chargeCount);
        }
        $this->quotes->money = new Money(777, 'IRR');
        $fresh = $this->service()->refreshReady($record->id, 'browser-refresh-2');
        self::assertSame(777, $fresh->money->amount);
        self::assertSame(2, $fresh->attempt);
        $this->central->chargeOutcome = PaymentOutcome::succeeded('refreshed-success');
        self::assertSame(777, $this->dispatch($record->id)->money->amount);
        self::assertSame([500, 777], array_map(fn ($a) => $a->amount, $this->service()->attemptHistory($record->id)));
    }

    public function test_definitive_failure_gets_fresh_price_and_keeps_prior_attempt_immutable(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::failed('provider-proved-no-debit');
        $this->dispatch($record->id);
        $this->quotes->money = new Money(750, 'IRR');
        $next = $this->service()->retryFailed($record->id, 'retry-with-price-750');
        self::assertSame(750, $next->money->amount);
        self::assertSame($next->id, $this->service()->retryFailed($record->id, 'retry-with-price-750')->id);
        $history = $this->service()->attemptHistory($record->id);
        self::assertSame([500, 750], array_map(fn ($a) => $a->amount, $history));
        self::assertSame('failed', $history[0]->status);
        $this->central->chargeOutcome = PaymentOutcome::succeeded('new-price-success');
        $this->dispatch($record->id);
        self::assertSame([500, 750], $this->central->chargeAmounts);
    }

    public function test_payment_unknown_blocks_repricing_and_retry(): void
    {
        $record = $this->create();
        $this->central->throwOnCharge = true;
        $this->dispatch($record->id);
        $this->quotes->money = new Money(900, 'IRR');
        $this->expectException(PaymentConflict::class);
        $this->service()->retryFailed($record->id, 'attempt-after-timeout');
    }

    public function test_automatic_refund_uses_original_success_amount_and_is_not_repeated(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::succeeded('original-500-ref');
        $this->dispatch($record->id);
        $this->refundPolicies->policy = RefundPolicy::nonRefundable(); // policy frozen at time of payment
        $this->asRole('government_admin', 'gov');
        $refund = $this->refunds()->propose($record->id, 'service.rejected', 'verified-rejection-1');
        self::assertSame(RefundStatus::Ready, $refund->status);
        self::assertSame(500, $refund->money->amount);
        self::assertSame($refund->id, $this->refunds()->propose($record->id, 'service.rejected', 'verified-rejection-1')->id);
        $this->refundCentral->refundOutcome = RefundOutcome::succeeded('central-full-refund');
        $settled = $this->refunds()->dispatch($refund->id);
        self::assertSame(RefundStatus::Succeeded, $settled->status);
        self::assertSame(1, $this->refundCentral->refundCalls);
        self::assertCount(3, $this->refunds()->history($refund->id));
        try { $this->refunds()->dispatch($refund->id); $this->fail('Second refund forbidden.'); }
        catch (\RuntimeException) { self::assertSame(1, $this->refundCentral->refundCalls); }
    }

    public function test_uncertain_refund_only_looks_up_same_key(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::succeeded('payment-ref');
        $this->dispatch($record->id);
        $this->asRole('government_admin', 'gov');
        $refund = $this->refunds()->propose($record->id, 'service.rejected', 'reject-event-1');
        $this->refundCentral->timeoutAfterSuccess = true;
        self::assertSame(RefundStatus::Unknown, $this->refunds()->dispatch($refund->id)->status);
        self::assertSame(RefundStatus::Succeeded, $this->refunds()->reconcile($refund->id)->status);
        self::assertSame(1, $this->refundCentral->refundCalls);
        self::assertSame(1, $this->refundCentral->lookupCalls);
    }

    public function test_admin_approval_is_required_and_agency_cannot_approve_even_if_role_gains_permission(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::succeeded('original');
        $this->dispatch($record->id);
        $refund = $this->refunds()->propose($record->id, 'request.cancelled', 'cancel-1');
        self::assertSame(RefundStatus::AwaitingApproval, $refund->status);
        Role::findByName('agency', 'web')->givePermissionTo('billing.refund.approve');
        try { $this->refunds()->approve($refund->id); $this->fail('Agency must not approve refund.'); }
        catch (AuthorizationDenied) { self::assertSame(0, $this->refundCentral->refundCalls); }
        $this->asRole('government_admin', 'gov');
        self::assertSame(RefundStatus::Ready, $this->refunds()->approve($refund->id)->status);
        $this->refundCentral->refundOutcome = RefundOutcome::succeeded('approved-full-refund');
        self::assertSame(RefundStatus::Succeeded, $this->refunds()->dispatch($refund->id)->status);
    }

    public function test_manual_refund_only_registers_case_and_cannot_invoke_refund_gateway(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::succeeded('original');
        $this->dispatch($record->id);
        $this->asRole('government_admin', 'gov');
        $refund = $this->refunds()->propose($record->id, 'issuance.failed', 'real-failure-1');
        self::assertSame(RefundStatus::ManualPending, $refund->status);
        try { $this->refunds()->dispatch($refund->id); $this->fail('Manual mode must not auto-send.'); }
        catch (\RuntimeException) { self::assertSame(0, $this->refundCentral->refundCalls); }
    }

    public function test_unverified_or_disallowed_refund_event_does_not_create_refund(): void
    {
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::succeeded('original');
        $this->dispatch($record->id);
        $this->asRole('government_admin', 'gov');
        $this->refundVerifier->allowed = false;
        try { $this->refunds()->propose($record->id, 'service.rejected', 'fake'); $this->fail('Must verify event.'); }
        catch (\RuntimeException) { $this->assertDatabaseCount('full_refunds', 0); }
        $this->refundVerifier->allowed = true;
        try { $this->refunds()->propose($record->id, 'invented.event', 'event-2'); $this->fail('Must enforce policy.'); }
        catch (\RuntimeException) { $this->assertDatabaseCount('full_refunds', 0); }
    }

    public function test_nonrefundable_snapshot_does_not_become_refundable_after_policy_change(): void
    {
        $this->refundPolicies->policy = RefundPolicy::nonRefundable();
        $record = $this->create();
        $this->central->chargeOutcome = PaymentOutcome::succeeded('original');
        $this->dispatch($record->id);
        $this->refundPolicies->policy = new RefundPolicy(['service.rejected' => RefundInitiation::Automatic]);
        $this->asRole('government_admin', 'gov');
        $this->expectException(\RuntimeException::class);
        $this->refunds()->propose($record->id, 'service.rejected', 'event-1');
    }

    public function test_reconcile_cannot_run_before_dispatch(): void
    {
        $record = $this->create();
        $this->expectException(PaymentConflict::class);
        $this->service()->reconcile($record->id);
    }
}

final class BillingFixturePrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;
    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('No authenticated billing principal.');
    }
}

final class BillingFixtureScopes implements ScopeChecker
{
    public bool $throw = false;
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($this->throw) { throw new \RuntimeException('Scope backend down.'); }
        if ($actor->role === 'agency') {
            return $actor->organizationId === 'agency-1' && $request->resourceId !== 'foreign-r';
        }
        return true; // only fixture; Spatie still checks explicit role permission
    }
}

final class BillingFixtureQuotes implements TrustedQuoteProvider
{
    public bool $payable = true;
    public Money $money;
    public function __construct() { $this->money = new Money(500, 'IRR'); }
    public function quote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): Money
    {
        if (!$this->payable || $serviceCode !== 'sample' || $resourceType !== 'billing.request'
            || $resourceId === 'foreign-r' || $agencyId !== 'agency-1') {
            throw new \RuntimeException('This resource is not payable for this agency and service.');
        }
        return $this->money;
    }
}

final class BillingFixtureCentral implements CentralPaymentGateway
{
    public int $chargeCount = 0;
    public int $lookupCount = 0;
    public array $chargeKeys = [];
    public array $chargeAmounts = [];
    public array $remoteSettled = [];
    public ?PaymentOutcome $chargeOutcome = null;
    public ?PaymentOutcome $lookupOutcome = null;
    public bool $throwOnCharge = false;
    public bool $remoteSuccessThenTimeout = false;

    public function preparePayment(PreparePaymentCommand $command): PreparePaymentOutcome
    {
        return PreparePaymentOutcome::prepared('central-prep-' . $command->paymentId, 0, 0, $command->amount->amount);
    }

    public function confirmPayment(ConfirmPaymentCommand $command): ConfirmPaymentOutcome
    {
        return ConfirmPaymentOutcome::succeeded($command->centralPaymentId, $command->amount->amount);
    }

    public function cancelPayment(CancelPaymentCommand $command): CancelPaymentOutcome
    {
        return CancelPaymentOutcome::cancelled($command->centralPaymentId);
    }

    public function lookupPayment(string $centralPaymentId): PaymentOutcome
    {
        $this->lookupCount++;
        return $this->remoteSettled[$centralPaymentId] ?? (reset($this->remoteSettled) ?: ($this->lookupOutcome ?? PaymentOutcome::unknown()));
    }

    public function lookupByOperationKey(string $operationKey): PaymentOutcome
    {
        $this->lookupCount++;
        return $this->remoteSettled[$operationKey] ?? (reset($this->remoteSettled) ?: ($this->lookupOutcome ?? PaymentOutcome::unknown()));
    }

    public function charge(PaymentCommand $command): PaymentOutcome
    {
        $this->chargeCount++;
        $this->chargeKeys[] = $command->requestKey;
        $this->chargeAmounts[] = $command->money->amount;
        if ($this->remoteSuccessThenTimeout) {
            $this->remoteSettled[$command->requestKey] = PaymentOutcome::succeeded('central-after-timeout');
            throw new \RuntimeException('Network timeout after central commit.');
        }
        if ($this->throwOnCharge) { throw new \RuntimeException('Connection dropped.'); }
        return $this->chargeOutcome ?? PaymentOutcome::pending();
    }

    public function lookup(PaymentCommand $command): PaymentOutcome
    {
        $this->lookupCount++;
        return $this->remoteSettled[$command->requestKey] ?? $this->lookupOutcome ?? PaymentOutcome::unknown();
    }
}

final class BillingFixtureUser extends BaseUser
{
    use HasRoles;
    protected $guard_name = 'web';
    protected $table = 'users';
    protected $guarded = [];
}

final class BillingFixtureRefundPolicies implements TrustedRefundPolicyProvider
{
    public RefundPolicy $policy;
    public function __construct() {
        $this->policy = new RefundPolicy([
            'service.rejected' => RefundInitiation::Automatic,
            'request.cancelled' => RefundInitiation::AdminApproval,
            'issuance.failed' => RefundInitiation::Manual,
        ]);
    }
    public function forPayment(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): RefundPolicy
    {
        if ($serviceCode !== 'sample' || $resourceType !== 'billing.request' || $agencyId !== 'agency-1') {
            throw new \RuntimeException('Untrusted refund policy resource.');
        }
        return $this->policy;
    }
}

final class BillingFixtureRefundVerifier implements TrustedRefundEventVerifier
{
    public bool $allowed = true;
    public function verify(string $eventCode, string $eventId, string $serviceCode, string $resourceType,
        string $resourceId, string $agencyId): bool
    {
        return $this->allowed && $eventId !== 'forged' && $serviceCode === 'sample'
            && $resourceType === 'billing.request' && $agencyId === 'agency-1';
    }
}

final class BillingFixtureRefundCentral implements CentralRefundGateway
{
    public int $refundCalls = 0;
    public int $lookupCalls = 0;
    public array $keys = [];
    public ?RefundOutcome $refundOutcome = null;
    public ?RefundOutcome $lookupOutcome = null;
    public bool $timeoutAfterSuccess = false;
    private array $remote = [];

    public function createRefund(RefundCommand $command): RefundOutcome
    {
        return $this->refund($command);
    }

    public function lookupRefund(string $centralRefundId): RefundOutcome
    {
        return RefundOutcome::succeeded($centralRefundId);
    }

    public function lookupRefundByOperationKey(string $operationKey): RefundOutcome
    {
        return RefundOutcome::succeeded('crf-by-opkey');
    }

    public function refund(RefundCommand $command): RefundOutcome
    {
        $this->refundCalls++;
        $this->keys[] = $command->requestKey;
        if ($this->timeoutAfterSuccess) {
            $this->remote[$command->requestKey] = RefundOutcome::succeeded('remote-refund-after-timeout');
            throw new \RuntimeException('Refund committed centrally; response dropped.');
        }
        return $this->refundOutcome ?? RefundOutcome::pending();
    }
    public function lookup(RefundCommand $command): RefundOutcome
    {
        $this->lookupCalls++;
        return $this->remote[$command->requestKey] ?? $this->lookupOutcome ?? RefundOutcome::unknown();
    }
}
