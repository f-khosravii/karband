<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Principal;
use Intl\Billing\Refund\RefundCommand;
use Intl\Billing\Refund\RefundOutcome;
use Intl\Billing\ValueObjects\CancelPaymentCommand;
use Intl\Billing\ValueObjects\CancelPaymentOutcome;
use Intl\Billing\ValueObjects\ConfirmPaymentCommand;
use Intl\Billing\ValueObjects\ConfirmPaymentOutcome;
use Intl\Billing\ValueObjects\Money;
use Intl\Billing\ValueObjects\PaymentCommand;
use Intl\Billing\ValueObjects\PaymentOutcome;
use Intl\Billing\ValueObjects\PaymentStatus;
use Intl\Billing\ValueObjects\PreparePaymentCommand;
use Intl\Billing\ValueObjects\PreparePaymentOutcome;
use Intl\BillingLaravel\Contracts\CentralPaymentGateway;
use Intl\BillingLaravel\Contracts\CentralRefundGateway;
use Intl\BillingLaravel\Contracts\PayableRequestGuard;
use Intl\BillingLaravel\Contracts\TrustedFinancialTermsProvider;
use Intl\BillingLaravel\Contracts\TrustedQuoteProvider;
use Intl\BillingLaravel\Contracts\TrustedRefundEventVerifier;
use Intl\BillingLaravel\Contracts\TrustedRefundPolicyProvider;
use Intl\BillingLaravel\Exceptions\PaymentConflict;
use Intl\BillingLaravel\Services\PaymentService;
use Intl\BillingLaravel\ValueObjects\FinancialTermsSnapshot;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\ServiceCatalog\Financial\PricingMode;
use Tests\TestCase;

final class IntlBillingV2Test extends TestCase
{
    use RefreshDatabase;

    private SandboxFakeCentralGateway $gateway;
    private SandboxFakeFinancialTermsProvider $termsProvider;
    private BillingV2TestPrincipalProvider $principals;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['intl-billing.client_namespace' => 'sandbox_v2_test']);

        $this->gateway = new SandboxFakeCentralGateway();
        $this->app->instance(CentralPaymentGateway::class, $this->gateway);

        $this->app->instance(CentralRefundGateway::class, new class implements CentralRefundGateway {
            public function createRefund(RefundCommand $command): RefundOutcome { return RefundOutcome::succeeded('crf-001'); }
            public function lookupRefund(string $id): RefundOutcome { return RefundOutcome::succeeded($id); }
            public function lookupRefundByOperationKey(string $key): RefundOutcome { return RefundOutcome::succeeded('crf-001'); }
            public function refund(RefundCommand $command): RefundOutcome { return RefundOutcome::succeeded('crf-001'); }
            public function lookup(RefundCommand $command): RefundOutcome { return RefundOutcome::succeeded('crf-001'); }
        });

        $this->termsProvider = new SandboxFakeFinancialTermsProvider();
        $this->app->instance(TrustedFinancialTermsProvider::class, $this->termsProvider);
        $this->app->instance(TrustedQuoteProvider::class, $this->termsProvider);
        $this->app->instance(TrustedRefundPolicyProvider::class, $this->termsProvider);

        $this->app->instance(PayableRequestGuard::class, new class implements PayableRequestGuard {
            public function assertPayable(string $s, string $rt, string $ri, string $a): void {}
            public function connectionName(): string { return \Illuminate\Support\Facades\DB::connection()->getName(); }
        });

        $this->app->instance(TrustedRefundEventVerifier::class, new class implements TrustedRefundEventVerifier {
            public function verify(string $e, string $ei, string $s, string $rt, string $ri, string $a): bool { return true; }
        });

        $this->principals = new BillingV2TestPrincipalProvider();
        $this->app->instance(PrincipalProvider::class, $this->principals);

        $this->app->instance(AuthorizerInterface::class, new class implements AuthorizerInterface {
            public function authorize(ActorContext $actor, $request): void {}
        });

        $this->paymentService = $this->app->make(PaymentService::class);
        $this->principals->actor = new ActorContext('user-sandbox-1', 'agency', 'agency-ncr');
    }

    /** Scenario A — fixed catalog flow */
    public function test_scenario_a_fixed_catalog_two_stage_billing(): void
    {
        $created = $this->paymentService->create('srv-ncr-business', 'request', 'req-scen-a', 'key-scen-a');
        self::assertSame(PaymentStatus::Ready, $created->status);
        self::assertSame('fixed_catalog', $created->pricingSource);
        self::assertSame(75000, $created->money->amount);

        $prepared = $this->paymentService->preparePayment($created->id, 'prep-op-scen-a');
        self::assertSame(PaymentStatus::Prepared, $prepared->status);
        self::assertSame('cp-req-scen-a', $prepared->centralPaymentId);
        self::assertSame(3000, $prepared->centralFeeAmount);
        self::assertSame(4500, $prepared->centralTaxAmount);
        self::assertSame(82500, $prepared->centralTotalAmount);

        $confirmed = $this->paymentService->confirmPayment($created->id, 'conf-op-scen-a');
        self::assertSame(PaymentStatus::Succeeded, $confirmed->status);
        self::assertSame(75000, $confirmed->capturedAmount);
    }

    /** Scenario B — lost confirmation response and recovery */
    public function test_scenario_b_lost_confirmation_response_reconciliation(): void
    {
        $created = $this->paymentService->create('srv-ncr-business', 'request', 'req-scen-b', 'key-scen-b');
        $this->paymentService->preparePayment($created->id, 'prep-op-scen-b');

        // Simulate transport timeout / lost HTTP response on confirm
        $this->gateway->failConfirmWithTransportError = true;
        $uncertain = $this->paymentService->confirmPayment($created->id, 'conf-op-scen-b');
        self::assertSame(PaymentStatus::Unknown, $uncertain->status);

        $initialConfirmCalls = $this->gateway->confirmCalls;

        // Reconcile retrieves central success without executing a second capture
        $this->gateway->failConfirmWithTransportError = false;
        $reconciled = $this->paymentService->reconcile($created->id);

        self::assertSame(PaymentStatus::Succeeded, $reconciled->status);
        self::assertSame($initialConfirmCalls, $this->gateway->confirmCalls); // No second capture call
    }

    /** Scenario C — no_charge pricing mode */
    public function test_scenario_c_no_charge_service_requires_no_central_payment(): void
    {
        $this->termsProvider->pricingMode = PricingMode::NoCharge;

        // PaymentService rejects creating central payment attempt for free services
        $this->expectException(PaymentConflict::class);
        $this->paymentService->create('srv-ncr-free', 'request', 'req-scen-c', 'key-scen-c');

        self::assertSame(0, $this->gateway->prepareCalls);
    }

    /** Scenario D — future request_quote pricing boundary */
    public function test_scenario_d_future_request_quote_pricing_boundary(): void
    {
        $this->termsProvider->pricingMode = PricingMode::RequestQuote;
        $this->termsProvider->price = 250000;

        $created = $this->paymentService->create('srv-ncr-quote', 'request', 'req-scen-d', 'key-scen-d');

        self::assertSame('request_quote', $created->pricingSource);
        self::assertSame(250000, $created->money->amount);

        $prepared = $this->paymentService->preparePayment($created->id, 'prep-op-scen-d');
        self::assertSame(PaymentStatus::Prepared, $prepared->status);
        self::assertSame(250000, $prepared->money->amount);
    }
}

final class SandboxFakeCentralGateway implements CentralPaymentGateway
{
    public int $prepareCalls = 0;
    public int $confirmCalls = 0;
    public bool $failConfirmWithTransportError = false;

    public function preparePayment(PreparePaymentCommand $command): PreparePaymentOutcome
    {
        $this->prepareCalls++;
        return PreparePaymentOutcome::prepared(
            centralPaymentId: 'cp-' . $command->resourceId,
            feeAmount: 3000,
            taxAmount: 4500,
            totalAmount: $command->amount->amount + 7500
        );
    }

    public function confirmPayment(ConfirmPaymentCommand $command): ConfirmPaymentOutcome
    {
        $this->confirmCalls++;
        if ($this->failConfirmWithTransportError) {
            return ConfirmPaymentOutcome::unknown($command->centralPaymentId, 'Transport error');
        }
        return ConfirmPaymentOutcome::succeeded($command->centralPaymentId, $command->amount->amount);
    }

    public function cancelPayment(CancelPaymentCommand $command): CancelPaymentOutcome
    {
        return CancelPaymentOutcome::cancelled($command->centralPaymentId);
    }

    public function lookupPayment(string $centralPaymentId): PaymentOutcome
    {
        return PaymentOutcome::succeeded($centralPaymentId, 75000);
    }

    public function lookupByOperationKey(string $operationKey): PaymentOutcome
    {
        return PaymentOutcome::succeeded('cp-operation-key', 75000);
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

final class SandboxFakeFinancialTermsProvider implements TrustedFinancialTermsProvider, TrustedQuoteProvider, TrustedRefundPolicyProvider
{
    public int $price = 75000;
    public string $currency = 'IRR';
    public array $refundEvents = ['request.cancelled' => 'automatic'];
    public PricingMode $pricingMode = PricingMode::FixedCatalog;

    public function snapshot(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): FinancialTermsSnapshot
    {
        return new FinancialTermsSnapshot(
            money: new Money($this->price, $this->currency),
            refundPolicy: \Intl\Billing\Refund\RefundPolicy::fromArray($this->refundEvents),
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

    public function forPayment(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): \Intl\Billing\Refund\RefundPolicy
    {
        return $this->snapshot($serviceCode, $resourceType, $resourceId, $agencyId)->refundPolicy;
    }
}

final class BillingV2TestPrincipalProvider implements PrincipalProvider
{
    public ActorContext $actor;

    public function current(): Principal
    {
        $user = new class extends \Illuminate\Foundation\Auth\User {
            protected $guarded = [];
        };
        return new Principal($user, $this->actor, 'web');
    }
}
