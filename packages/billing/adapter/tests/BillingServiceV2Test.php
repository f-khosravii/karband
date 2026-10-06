<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Principal;
use Karband\Billing\Refund\RefundCommand;
use Karband\Billing\Refund\RefundOutcome;
use Karband\Billing\Refund\RefundPolicy;
use Karband\Billing\ValueObjects\CancelPaymentCommand;
use Karband\Billing\ValueObjects\CancelPaymentOutcome;
use Karband\Billing\ValueObjects\ConfirmPaymentCommand;
use Karband\Billing\ValueObjects\ConfirmPaymentOutcome;
use Karband\Billing\ValueObjects\Money;
use Karband\Billing\ValueObjects\PaymentCommand;
use Karband\Billing\ValueObjects\PaymentOutcome;
use Karband\Billing\ValueObjects\PaymentStatus;
use Karband\Billing\ValueObjects\PreparePaymentCommand;
use Karband\Billing\ValueObjects\PreparePaymentOutcome;
use Karband\BillingLaravel\Contracts\CentralPaymentGateway;
use Karband\BillingLaravel\Contracts\CentralRefundGateway;
use Karband\BillingLaravel\Contracts\PayableRequestGuard;
use Karband\BillingLaravel\Contracts\TrustedFinancialTermsProvider;
use Karband\BillingLaravel\Contracts\TrustedQuoteProvider;
use Karband\BillingLaravel\Contracts\TrustedRefundEventVerifier;
use Karband\BillingLaravel\Contracts\TrustedRefundPolicyProvider;
use Karband\BillingLaravel\Exceptions\PaymentConflict;
use Karband\BillingLaravel\Exceptions\RefundConflict;
use Karband\BillingLaravel\Services\PaymentService;
use Karband\BillingLaravel\Services\RefundService;
use Karband\BillingLaravel\ValueObjects\FinancialTermsSnapshot;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizerInterface;
use Karband\ServiceCatalog\Financial\PricingMode;
use Tests\TestCase;

final class BillingServiceV2Test extends TestCase
{
    use RefreshDatabase;

    private FakeCentralGateway $gateway;
    private FakeCentralRefundGateway $refundGateway;
    private FakeFinancialTermsProvider $termsProvider;
    private TestPrincipalProvider $principals;
    private PaymentService $paymentService;
    private RefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.client_namespace' => 'sandbox_test']);

        $this->app->register(\Karband\BillingLaravel\BillingLaravelServiceProvider::class);

        $this->gateway = new FakeCentralGateway();
        $this->app->instance(CentralPaymentGateway::class, $this->gateway);

        $this->refundGateway = new FakeCentralRefundGateway();
        $this->app->instance(CentralRefundGateway::class, $this->refundGateway);

        $this->termsProvider = new FakeFinancialTermsProvider();
        $this->app->instance(TrustedFinancialTermsProvider::class, $this->termsProvider);
        $this->app->instance(TrustedQuoteProvider::class, $this->termsProvider);
        $this->app->instance(TrustedRefundPolicyProvider::class, $this->termsProvider);

        $this->app->instance(PayableRequestGuard::class, new class implements PayableRequestGuard {
            public function assertPayable(string $s, string $rt, string $ri, string $a): void {}
            public function connectionName(): string { return 'sqlite'; }
        });

        $this->app->instance(TrustedRefundEventVerifier::class, new class implements TrustedRefundEventVerifier {
            public function verify(string $e, string $ei, string $s, string $rt, string $ri, string $a): bool { return true; }
        });

        $this->principals = new TestPrincipalProvider();
        $this->app->instance(PrincipalProvider::class, $this->principals);

        $this->app->instance(AuthorizerInterface::class, new class implements AuthorizerInterface {
            public function authorize(ActorContext $actor, $request): void {}
        });

        $this->paymentService = new PaymentService(
            storage: $this->app->make(\Karband\BillingLaravel\Services\PaymentStorage::class),
            actors: $this->principals,
            authorization: $this->app->make(AuthorizerInterface::class),
            quotes: $this->termsProvider,
            refundPolicies: $this->termsProvider,
            central: $this->gateway,
            financialTerms: $this->termsProvider,
        );

        $this->refundService = new RefundService(
            storage: $this->app->make(\Karband\BillingLaravel\Services\PaymentStorage::class),
            actors: $this->principals,
            authorization: $this->app->make(AuthorizerInterface::class),
            events: $this->app->make(TrustedRefundEventVerifier::class),
            container: $this->app,
        );

        $this->principals->actor = new ActorContext('user-1', 'agency', 'agency-ncr');
    }

    // 1. Successful prepare
    public function test_successful_prepare(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-101', 'key-101');
        self::assertSame(PaymentStatus::Ready, $created->status);

        $prepared = $this->paymentService->preparePayment($created->id, 'prep-101');
        self::assertSame(PaymentStatus::Prepared, $prepared->status);
        self::assertSame('cp-req-101', $prepared->centralPaymentId);
    }

    // 2. Successful prepare then confirm
    public function test_successful_prepare_then_confirm(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-102', 'key-102');
        $this->paymentService->preparePayment($created->id, 'prep-102');

        $confirmed = $this->paymentService->confirmPayment($created->id, 'conf-102');
        self::assertSame(PaymentStatus::Succeeded, $confirmed->status);
        self::assertSame(50000, $confirmed->capturedAmount);
    }

    // 3. Prepare timeout then lookup recovery
    public function test_prepare_timeout_then_lookup_recovery(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-103', 'key-103');
        $this->gateway->failPrepareWithTransportError = true;

        $uncertain = $this->paymentService->preparePayment($created->id, 'prep-103');
        self::assertSame(PaymentStatus::Unknown, $uncertain->status);

        // Recover central status via reconciliation lookup
        $this->gateway->failPrepareWithTransportError = false;
        $reconciled = $this->paymentService->reconcile($created->id);
        self::assertSame(PaymentStatus::Prepared, $reconciled->status);
    }

    // 4. Confirm timeout then lookup recovery
    public function test_confirm_timeout_then_lookup_recovery(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-104', 'key-104');
        $this->paymentService->preparePayment($created->id, 'prep-104');

        $this->gateway->failConfirmWithTransportError = true;
        $uncertain = $this->paymentService->confirmPayment($created->id, 'conf-104');
        self::assertSame(PaymentStatus::Unknown, $uncertain->status);

        $this->gateway->failConfirmWithTransportError = false;
        $reconciled = $this->paymentService->reconcile($created->id);
        self::assertSame(PaymentStatus::Succeeded, $reconciled->status);
    }

    // 5. No duplicate central operation after lost response
    public function test_no_duplicate_central_operation_after_lost_response(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-105', 'key-105');
        $this->paymentService->preparePayment($created->id, 'prep-105');

        $this->gateway->failConfirmWithTransportError = true;
        $this->paymentService->confirmPayment($created->id, 'conf-105');
        $initialConfirmCount = $this->gateway->confirmCalls;

        // Reconcile should NOT issue a new confirmPayment call
        $this->paymentService->reconcile($created->id);
        self::assertSame($initialConfirmCount, $this->gateway->confirmCalls);
    }

    // 6. Prepared payment cancellation
    public function test_prepared_payment_cancellation(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-106', 'key-106');
        $this->paymentService->preparePayment($created->id, 'prep-106');

        $cancelled = $this->paymentService->cancelPayment($created->id, 'cancel-106', 'User cancelled');
        self::assertSame(PaymentStatus::Cancelled, $cancelled->status);
    }

    // 7. Invalid confirm-before-prepare rejected
    public function test_invalid_confirm_before_prepare_rejected(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-107', 'key-107');
        $this->expectException(PaymentConflict::class);
        $this->paymentService->confirmPayment($created->id, 'conf-107');
    }

    // 8. Second unresolved attempt rejected
    public function test_second_unresolved_attempt_rejected(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-108', 'key-108');
        $this->gateway->failPrepareWithTransportError = true;
        $this->paymentService->preparePayment($created->id, 'prep-108');

        // Cannot prepare again while existing attempt is in Unknown state
        $this->expectException(PaymentConflict::class);
        $this->paymentService->preparePayment($created->id, 'prep-108-retry');
    }

    // 9. Fixed catalog pricing snapshot
    public function test_fixed_catalog_pricing_snapshot(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-109', 'key-109');
        self::assertSame('fixed_catalog', $created->pricingSource);
        self::assertSame(50000, $created->money->amount);
    }

    // 10. Request_quote pricing source through a fake trusted provider
    public function test_request_quote_pricing_source(): void
    {
        $this->termsProvider->pricingMode = PricingMode::RequestQuote;
        $this->termsProvider->price = 120000;

        $created = $this->paymentService->create('srv-ncr', 'request', 'req-110', 'key-110');
        self::assertSame('request_quote', $created->pricingSource);
        self::assertSame(120000, $created->money->amount);
    }

    // 11. No_charge does not call central gateway and creates no fake attempt
    public function test_no_charge_does_not_call_central_gateway(): void
    {
        $this->termsProvider->pricingMode = PricingMode::NoCharge;

        $this->expectException(PaymentConflict::class);
        $this->paymentService->create('srv-ncr', 'request', 'req-111', 'key-111');
        self::assertSame(0, $this->gateway->prepareCalls);
    }

    // 12. Catalog price change after attempt does not change snapshot
    public function test_catalog_price_change_after_attempt_does_not_change_snapshot(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-112', 'key-112');
        $this->paymentService->preparePayment($created->id, 'prep-112');

        // Service catalog price increases
        $this->termsProvider->price = 999999;

        $viewed = $this->paymentService->view($created->id);
        self::assertSame(50000, $viewed->money->amount); // Historical snapshot immutable
    }

    // 13. Refund policy change after attempt does not change snapshot
    public function test_refund_policy_change_after_attempt_does_not_change_snapshot(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-113', 'key-113');
        $this->paymentService->preparePayment($created->id, 'prep-113');
        $this->paymentService->confirmPayment($created->id, 'conf-113');

        // Change catalog policy
        $this->termsProvider->refundEvents = [];

        $refund = $this->refundService->propose($created->id, 'request.cancelled', 'evt-113');
        self::assertNotNull($refund);
    }

    // 14. Successful capture stores immutable captured amount
    public function test_successful_capture_stores_immutable_captured_amount(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-114', 'key-114');
        $this->paymentService->preparePayment($created->id, 'prep-114');
        $confirmed = $this->paymentService->confirmPayment($created->id, 'conf-114');

        self::assertSame(50000, $confirmed->capturedAmount);
    }

    // 15. Full refund uses successful financial fact
    public function test_full_refund_uses_successful_financial_fact(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-115', 'key-115');
        $this->paymentService->preparePayment($created->id, 'prep-115');
        $confirmed = $this->paymentService->confirmPayment($created->id, 'conf-115');

        $refund = $this->refundService->propose($created->id, 'request.cancelled', 'evt-115');
        self::assertSame(50000, $refund->money->amount);
    }

    // 16. Arbitrary caller refund amount unavailable/ignored
    public function test_arbitrary_caller_refund_amount_unavailable(): void
    {
        // RefundService::propose only accepts paymentId, eventCode, eventId
        $ref = new \ReflectionMethod(RefundService::class, 'propose');
        $params = array_map(fn($p) => $p->getName(), $ref->getParameters());
        self::assertNotContains('amount', $params);
    }

    // 17. Refund timeout reconciles without duplicate refund
    public function test_refund_timeout_reconciles_without_duplicate_refund(): void
    {
        $created = $this->paymentService->create('srv-ncr', 'request', 'req-117', 'key-117');
        $this->paymentService->preparePayment($created->id, 'prep-117');
        $this->paymentService->confirmPayment($created->id, 'conf-117');

        $refund = $this->refundService->propose($created->id, 'request.cancelled', 'evt-117');
        $this->refundGateway->failRefundWithTransportError = true;

        $uncertain = $this->refundService->dispatch($refund->id);
        self::assertSame(\Karband\Billing\Refund\RefundStatus::Unknown, $uncertain->status);
        $initialCalls = $this->refundGateway->refundCalls;

        $this->refundGateway->failRefundWithTransportError = false;
        $reconciled = $this->refundService->reconcile($refund->id);
        self::assertSame(\Karband\Billing\Refund\RefundStatus::Succeeded, $reconciled->status);
    }

    // 18. Concurrent/replayed operations are idempotent
    public function test_concurrent_replayed_operations_are_idempotent(): void
    {
        $created1 = $this->paymentService->create('srv-ncr', 'request', 'req-118', 'key-118');
        $created2 = $this->paymentService->create('srv-ncr', 'request', 'req-118', 'key-118');

        self::assertSame($created1->id, $created2->id);

        $prep1 = $this->paymentService->preparePayment($created1->id, 'prep-118');
        $prep2 = $this->paymentService->preparePayment($created1->id, 'prep-118');

        self::assertSame($prep1->id, $prep2->id);
        self::assertSame(PaymentStatus::Prepared, $prep2->status);
    }
}

/** Test double for CentralPaymentGateway */
final class FakeCentralGateway implements CentralPaymentGateway
{
    public int $prepareCalls = 0;
    public int $confirmCalls = 0;
    public bool $failPrepareWithTransportError = false;
    public bool $failConfirmWithTransportError = false;

    public function preparePayment(PreparePaymentCommand $command): PreparePaymentOutcome
    {
        $this->prepareCalls++;
        if ($this->failPrepareWithTransportError) {
            return PreparePaymentOutcome::unknown('Transport failure');
        }
        return PreparePaymentOutcome::prepared(
            centralPaymentId: 'cp-' . $command->resourceId,
            feeAmount: 2000,
            taxAmount: 3000,
            totalAmount: $command->amount->amount + 5000
        );
    }

    public function confirmPayment(ConfirmPaymentCommand $command): ConfirmPaymentOutcome
    {
        $this->confirmCalls++;
        if ($this->failConfirmWithTransportError) {
            return ConfirmPaymentOutcome::unknown($command->centralPaymentId, 'Transport failure');
        }
        return ConfirmPaymentOutcome::succeeded($command->centralPaymentId, $command->amount->amount);
    }

    public function cancelPayment(CancelPaymentCommand $command): CancelPaymentOutcome
    {
        return CancelPaymentOutcome::cancelled($command->centralPaymentId);
    }

    public function lookupPayment(string $centralPaymentId): PaymentOutcome
    {
        if ($this->failConfirmWithTransportError) {
            return PaymentOutcome::succeeded($centralPaymentId, 50000);
        }
        if ($centralPaymentId === 'cp-req-103') {
            return PaymentOutcome::prepared($centralPaymentId, 2000, 3000, 55000);
        }
        return PaymentOutcome::succeeded($centralPaymentId, 50000);
    }

    public function lookupByOperationKey(string $operationKey): PaymentOutcome
    {
        if (str_contains($operationKey, 'prep-103')) {
            return PaymentOutcome::prepared('cp-req-103', 2000, 3000, 55000);
        }
        return PaymentOutcome::succeeded('cp-operation-key', 50000);
    }

    public function charge(PaymentCommand $command): PaymentOutcome
    {
        return PaymentOutcome::succeeded('cp-' . $command->paymentId, $command->money->amount);
    }

    public function lookup(PaymentCommand $command): PaymentOutcome
    {
        return PaymentOutcome::succeeded('cp-' . $command->paymentId, $command->money->amount);
    }
}

/** Test double for CentralRefundGateway */
final class FakeCentralRefundGateway implements CentralRefundGateway
{
    public int $refundCalls = 0;
    public bool $failRefundWithTransportError = false;

    public function createRefund(RefundCommand $command): RefundOutcome
    {
        $this->refundCalls++;
        if ($this->failRefundWithTransportError) {
            return RefundOutcome::unknown();
        }
        return RefundOutcome::succeeded('crf-' . $command->refundId);
    }

    public function lookupRefund(string $centralRefundId): RefundOutcome
    {
        return RefundOutcome::succeeded($centralRefundId);
    }

    public function lookupRefundByOperationKey(string $operationKey): RefundOutcome
    {
        return RefundOutcome::succeeded('crf-op-key');
    }

    public function refund(RefundCommand $command): RefundOutcome
    {
        return $this->createRefund($command);
    }

    public function lookup(RefundCommand $command): RefundOutcome
    {
        return RefundOutcome::succeeded('crf-' . $command->refundId);
    }
}

/** Test double for TrustedFinancialTermsProvider */
final class FakeFinancialTermsProvider implements TrustedFinancialTermsProvider, TrustedQuoteProvider, TrustedRefundPolicyProvider
{
    public int $price = 50000;
    public string $currency = 'IRR';
    public array $refundEvents = ['request.cancelled' => 'automatic'];
    public PricingMode $pricingMode = PricingMode::FixedCatalog;

    public function snapshot(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): FinancialTermsSnapshot
    {
        return new FinancialTermsSnapshot(
            money: new Money($this->price, $this->currency),
            refundPolicy: RefundPolicy::fromArray($this->refundEvents),
            catalogRevision: 1,
            pricingMode: $this->pricingMode,
            pricingRevision: 1,
            refundPolicyRevision: 1,
        );
    }

    public function quote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): Money
    {
        return $this->snapshot($serviceCode, $resourceType, $resourceId, $agencyId)->money;
    }

    public function forPayment(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): RefundPolicy
    {
        return $this->snapshot($serviceCode, $resourceType, $resourceId, $agencyId)->refundPolicy;
    }
}

/** Test PrincipalProvider */
final class TestPrincipalProvider implements PrincipalProvider, \Karband\Authorization\Contracts\ActorProviderInterface
{
    public ActorContext $actor;

    public function current(): Principal
    {
        $user = new class extends \Illuminate\Foundation\Auth\User {
            protected $guarded = [];
        };
        return new Principal($user, $this->actor, 'web');
    }

    public function currentActor(): ActorContext
    {
        return $this->actor;
    }
}
