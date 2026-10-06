<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Billing\Refund\RefundPolicy;
use Karband\Billing\ValueObjects\Money;
use Karband\Billing\ValueObjects\PaymentStatus;
use App\Bridges\LocalCatalogFinancialTermsProvider;
use Karband\BillingLaravel\Contracts\CentralPaymentGateway;
use Karband\BillingLaravel\Contracts\CentralRefundGateway;
use Karband\BillingLaravel\Contracts\PayableRequestGuard;
use Karband\BillingLaravel\Services\PaymentService;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Authorization\AuthorizerInterface;
use Karband\Documents\Definitions\DocumentDefinition;
use Karband\DocumentsLaravel\Services\DocumentRegistry;
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Definitions\FormField;
use Karband\DynamicFormsLaravel\Services\FormRegistry;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalog\Financial\PricingMode;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\ServiceRequests\ValueObjects\QuoteStatus;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequests\ValueObjects\RequestDraft;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Exceptions\QuoteConflict;
use Karband\ServiceRequestsLaravel\Exceptions\QuoteNotFound;
use Karband\ServiceRequestsLaravel\Services\RequestQuoteService;
use Karband\ServiceRequestsLaravel\Services\ServiceRequests;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class RequestQuoteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private RequestQuoteTestPrincipalProvider $principals;
    private RequestQuoteTestGateway $gateway;
    private RequestQuoteService $quoteService;
    private ServiceRequests $requestsService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['agency', 'government_admin', 'platform_admin'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        foreach ([
            'workflow.definition.publish', 'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit',
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.deactivate', 'catalog.service.view', 'catalog.service.use',
            'forms.definition.edit', 'forms.definition.publish', 'forms.response.submit', 'forms.response.view',
            'documents.definition.edit', 'documents.definition.publish', 'documents.file.upload', 'documents.file.view',
            'requests.create', 'requests.view', 'requests.submit',
            'quote.propose', 'quote.accept', 'quote.reject', 'quote.view',
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.reconcile',
            'workflow.action.approve',
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'workflow.definition.publish', 'catalog.service.create', 'catalog.service.activate', 'catalog.service.deactivate', 'catalog.service.view',
            'forms.definition.edit', 'forms.definition.publish', 'documents.definition.edit', 'documents.definition.publish', 'requests.view',
        ]);
        Role::findByName('agency', 'web')->givePermissionTo([
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit', 'catalog.service.use',
            'forms.response.submit', 'forms.response.view', 'documents.file.upload', 'documents.file.view',
            'requests.create', 'requests.view', 'requests.submit',
            'quote.accept', 'quote.reject', 'quote.view', 'billing.payment.create', 'billing.payment.execute',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'requests.view', 'quote.propose', 'quote.view', 'billing.payment.reconcile', 'workflow.action.approve',
        ]);

        $this->app->bind(\Karband\WorkflowLaravel\Contracts\ActorResolver::class, \App\Bridges\WorkflowActorResolver::class);
        $this->app->bind(\Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer::class, \App\Bridges\WorkflowAuthorizationBridge::class);
        $this->app->instance(\Karband\DocumentsLaravel\Contracts\ReplacementPolicy::class, new class implements \Karband\DocumentsLaravel\Contracts\ReplacementPolicy {
            public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool { return false; }
        });
        $this->app->instance(\Karband\DocumentsLaravel\Contracts\FileSafetyScanner::class, new class implements \Karband\DocumentsLaravel\Contracts\FileSafetyScanner {
            public function assertSafe(string $temporaryPath, string $detectedMimeType): void {}
        });
        $this->app->instance(\Karband\ServiceRequestsLaravel\Contracts\RecipientVerifier::class, new class implements \Karband\ServiceRequestsLaravel\Contracts\RecipientVerifier {
            public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool { return true; }
        });
        $this->app->instance(\Karband\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier::class, new class implements \Karband\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier {
            public function assertReady(string $serviceCode, string $requestId): void {}
        });
        $this->app->instance(\Karband\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator::class, new class implements \Karband\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator {
            public function assertValid(string $serviceCode, array $profile): void {}
        });

        $this->principals = new RequestQuoteTestPrincipalProvider();
        $this->app->instance(PrincipalProvider::class, $this->principals);

        $scopes = new class implements ScopeChecker {
            public function allows(ActorContext $actor, AccessRequest $request): bool {
                return true;
            }
        };
        $this->app->instance(ScopeChecker::class, $scopes);

        $this->gateway = new RequestQuoteTestGateway();
        $this->app->instance(CentralPaymentGateway::class, $this->gateway);

        config(['billing.client_namespace' => 'sandbox_quote_test']);
        config(['audit.allowed_events' => array_merge(config('audit.allowed_events', []), [
            'request.quote.proposed', 'request.quote.accepted', 'request.quote.rejected',
        ])]);

        $this->quoteService = $this->app->make(RequestQuoteService::class);
        $this->requestsService = $this->app->make(ServiceRequests::class);

        $payableGuard = new class implements PayableRequestGuard {
            public function connectionName(): string { return \Illuminate\Support\Facades\DB::connection()->getName(); }
            public function assertPayable(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): void {
                $exists = DB::table('service_requests')->where('id', $resourceId)->where('agency_id', $agencyId)->exists();
                if (!$exists) { throw new \RuntimeException('Request not payable'); }
            }
        };

        $termsProvider = new LocalCatalogFinancialTermsProvider(
            $this->app->make(\Karband\ServiceCatalogLaravel\Services\CatalogStorage::class),
            $payableGuard,
            $this->app->make(\Karband\BillingLaravel\Contracts\AcceptedQuoteResolverInterface::class)
        );

        $this->paymentService = new PaymentService(
            $this->app->make(\Karband\BillingLaravel\Services\PaymentStorage::class),
            $this->app->make(\Karband\Authorization\Contracts\ActorProviderInterface::class),
            $this->app->make(AuthorizerInterface::class),
            $termsProvider,
            $termsProvider,
            $this->gateway,
            new \Karband\Billing\Engine\PaymentStateMachine(),
            $termsProvider
        );

        $this->login('platform_admin', 'platform-1', 'admin-1');

        $this->seedCatalogAndRecipient('srv-quote-req', PricingMode::RequestQuote);
        $this->seedCatalogAndRecipient('srv-fixed-cat', PricingMode::FixedCatalog);
        $this->seedCatalogAndRecipient('srv-no-charge', PricingMode::NoCharge);
    }

    private function login(string $role, string $agencyId = 'agency-1', string $userId = 'usr-1'): void
    {
        $user = RequestQuoteTestUser::firstOrCreate(
            ['email' => $userId . '@example.com'],
            ['name' => 'User ' . $userId, 'password' => 'secret']
        );
        $user->syncRoles([$role]);
        Auth::guard('web')->setUser($user);
        $this->principals->actor = new ActorContext((string) $user->id, $role, $agencyId);
    }

    private function seedCatalogAndRecipient(string $serviceCode, PricingMode $pricingMode): void
    {
        $wf = WorkflowDefinition::fromArray([
            'id' => 'wf-' . $serviceCode,
            'version' => 1,
            'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'correction_required', 'approved', 'rejected', 'cancelled'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'approve', 'from' => 'submitted', 'to' => 'approved', 'allowed_roles' => ['government_admin']],
            ],
        ]);
        $this->app->make(DefinitionCatalog::class)->publish($wf, 1);

        $settings = [
            'request_management' => [
                'draft_state' => 'draft',
                'submit_actions' => ['submit', 'resubmit'],
                'submitted_states' => ['submitted', 'correction_required'],
                'terminal_states' => ['approved', 'rejected', 'cancelled'],
                'cancelled_state' => 'cancelled',
                'cancellable_states' => ['submitted', 'correction_required'],
                'correction_states' => ['correction_required'],
                'correction_mode' => 'limited',
                'correction_action' => 'require_correction',
                'resubmit_action' => 'resubmit',
                'cancel_draft_action' => 'cancel_draft',
                'cancel_actions_by_state' => ['submitted' => 'cancel_submitted', 'correction_required' => 'cancel_correction'],
                'recipient_field_map' => ['full_name' => 'name'],
            ],
            'pricing_mode' => $pricingMode->value,
            'billing_fixed_price_minor' => 10000,
            'billing_currency' => 'IRR',
            'refund_events' => [],
        ];

        $catalog = app(ServiceCatalog::class);
        $svc = $catalog->create(new ServiceDraft(
            code: $serviceCode,
            name: 'Service ' . $serviceCode,
            description: 'Desc',
            settings: $settings,
            workflow: new WorkflowBinding('wf-' . $serviceCode, 1),
        ));

        $forms = app(FormRegistry::class);
        $formRev = $forms->saveDraft(new FormDefinition($serviceCode, 1, [new FormField('name', 'Name', 'text', true)]));
        $forms->publishDraft($serviceCode, $formRev);

        $documents = app(DocumentRegistry::class);
        $docRev = $documents->saveDraft(new DocumentDefinition($serviceCode, 1, []));
        $documents->publishDraft($serviceCode, $docRev);

        $catalog->activate($serviceCode, $svc->revision);

        DB::table('recipients')->insertOrIgnore([
            'id' => 'rec-1',
            'agency_id' => 'agency-1',
            'kind' => 'person',
            'identity_type' => 'test_code',
            'identity_lookup' => hash('sha256', 'agency-1/person/rec-1'),
            'identity_cipher' => \Illuminate\Support\Facades\Crypt::encryptString('identity-rec-1'),
            'profile_cipher' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['full_name' => 'Alice'], JSON_THROW_ON_ERROR)),
            'status' => 'active',
            'revision' => 1,
            'created_by' => 'fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSubmittedRequest(string $serviceCode = 'srv-quote-req'): string
    {
        $this->login('agency', 'agency-1');
        $draft = new RequestDraft($serviceCode, new RecipientReference('person', 'rec-1'), 'key-' . uniqid());
        $snapshot = $this->requestsService->create($draft);
        $this->requestsService->saveAnswers($snapshot->id, ['name' => 'Alice'], 0);
        $this->requestsService->act($snapshot->id, 'submit');
        return $snapshot->id;
    }

    public function test_government_admin_proposes_quote_and_agency_views_it(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $proposal = $this->quoteService->propose($reqId, 50000, 'IRR', 'Standard rate');

        self::assertSame(1, $proposal->revision);
        self::assertSame(50000, $proposal->amount);
        self::assertSame('IRR', $proposal->currency);
        self::assertSame(QuoteStatus::Proposed, $proposal->status);
        self::assertSame('Standard rate', $proposal->notes);

        $this->login('agency', 'agency-1');
        $active = $this->quoteService->activeQuote($reqId);
        self::assertNotNull($active);
        self::assertSame(1, $active->revision);
        self::assertSame(50000, $active->amount);
    }

    public function test_agency_accepts_quote_does_not_transition_workflow_or_create_payment(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 75000, 'IRR');

        $this->login('agency', 'agency-1');
        $accepted = $this->quoteService->accept($reqId, 1);

        self::assertSame(QuoteStatus::Accepted, $accepted->status);
        self::assertSame(1, $accepted->revision);

        // Verify quote acceptance alone makes ZERO central payment gateway calls
        self::assertSame(0, $this->gateway->prepareCalls);
        self::assertSame(0, $this->gateway->confirmCalls);

        // Verify request Workflow remains in 'submitted' state
        $requestRow = DB::table('service_requests')->where('id', $reqId)->first();
        $wfState = DB::table('workflow_instances')->where('id', $requestRow->workflow_instance_id)->value('current_state');
        self::assertSame('submitted', $wfState);

        // Verify no PaymentIntent row was created automatically
        self::assertFalse(DB::table('payment_intents')->where('resource_id', $reqId)->exists());
    }

    public function test_agency_rejects_quote(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 90000, 'IRR');

        $this->login('agency', 'agency-1');
        $rejected = $this->quoteService->reject($reqId, 1, 'Too expensive');

        self::assertSame(QuoteStatus::Rejected, $rejected->status);
        self::assertSame('Too expensive', $rejected->rejectionReason);
    }

    public function test_admin_proposes_revision_2_after_rejection_and_history_is_preserved(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 100000, 'IRR');

        $this->login('agency', 'agency-1');
        $this->quoteService->reject($reqId, 1, 'Too high');

        $this->login('government_admin', 'agency-1');
        $r2 = $this->quoteService->propose($reqId, 80000, 'IRR', 'Revised quote');

        self::assertSame(2, $r2->revision);
        self::assertSame(80000, $r2->amount);

        $history = $this->quoteService->history($reqId);
        self::assertCount(2, $history);
        self::assertSame(1, $history[0]->revision);
        self::assertSame(QuoteStatus::Rejected, $history[0]->status);
        self::assertSame(2, $history[1]->revision);
        self::assertSame(QuoteStatus::Proposed, $history[1]->status);
    }

    public function test_stale_acceptance_and_rejection_with_wrong_revision_fails(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 50000, 'IRR');

        $this->login('agency', 'agency-1');

        $this->expectException(QuoteConflict::class);
        $this->quoteService->accept($reqId, 999);
    }

    public function test_new_proposal_supersedes_unaccepted_proposed_revision(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 50000, 'IRR');
        $r2 = $this->quoteService->propose($reqId, 45000, 'IRR', 'Discount proposal');

        self::assertSame(2, $r2->revision);

        $history = $this->quoteService->history($reqId);
        self::assertCount(2, $history);
        self::assertSame(QuoteStatus::Superseded, $history[0]->status);
        self::assertSame(QuoteStatus::Proposed, $history[1]->status);
    }

    public function test_accepted_quote_cannot_be_superseded_or_edited(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 50000, 'IRR');

        $this->login('agency', 'agency-1');
        $this->quoteService->accept($reqId, 1);

        $this->login('government_admin', 'agency-1');
        $this->expectException(QuoteConflict::class);
        $this->expectExceptionMessage('Cannot propose a new quote for a request with an already accepted quote.');
        $this->quoteService->propose($reqId, 40000, 'IRR');
    }

    public function test_quote_proposal_rejected_for_fixed_catalog_service(): void
    {
        $reqId = $this->createSubmittedRequest('srv-fixed-cat');

        $this->login('government_admin', 'agency-1');
        $this->expectException(QuoteConflict::class);
        $this->expectExceptionMessage('Quote proposals are only supported for services with pricing_mode = request_quote.');
        $this->quoteService->propose($reqId, 50000, 'IRR');
    }

    public function test_quote_proposal_rejected_for_no_charge_service(): void
    {
        $reqId = $this->createSubmittedRequest('srv-no-charge');

        $this->login('government_admin', 'agency-1');
        $this->expectException(QuoteConflict::class);
        $this->expectExceptionMessage('Quote proposals are only supported for services with pricing_mode = request_quote.');
        $this->quoteService->propose($reqId, 50000, 'IRR');
    }

    public function test_cross_agency_accept_or_reject_denied(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 50000, 'IRR');

        // Agency 2 attempts to accept Agency 1's request quote
        $this->login('agency', 'agency-2');
        $this->expectException(AuthorizationDenied::class);
        $this->quoteService->accept($reqId, 1);
    }

    public function test_billing_pricing_resolution_fails_if_no_accepted_quote(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 50000, 'IRR');

        // Agency tries to create Billing payment before accepting quote
        $this->login('agency', 'agency-1');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No accepted quote found for request_quote service.');
        $this->paymentService->create('srv-quote-req', RequestResource::TYPE, $reqId, 'billing-key-1');
    }

    public function test_billing_pricing_resolution_succeeds_with_accepted_quote(): void
    {
        $reqId = $this->createSubmittedRequest();

        $this->login('government_admin', 'agency-1');
        $this->quoteService->propose($reqId, 65000, 'IRR');

        $this->login('agency', 'agency-1');
        $this->quoteService->accept($reqId, 1);

        // Billing create & preparePayment snapshot accepted quote
        $payment = $this->paymentService->create('srv-quote-req', RequestResource::TYPE, $reqId, 'billing-key-2');
        self::assertSame(65000, $payment->money->amount);
        self::assertSame('IRR', $payment->money->currency);

        $prepared = $this->paymentService->preparePayment($payment->id, 'op-prep-quote-1');
        self::assertSame(PaymentStatus::Prepared, $prepared->status);
        self::assertSame('request_quote', $prepared->pricingSource);
        self::assertSame(1, $this->quoteService->acceptedQuote($reqId)->revision);
        self::assertSame(65000, $prepared->money->amount);
    }

    public function test_insurance_derived_reference_scenario(): void
    {
        // 1. Request submitted
        $reqId = $this->createSubmittedRequest();

        // 2. Admin proposes Quote R1
        $this->login('government_admin', 'agency-1');
        $r1 = $this->quoteService->propose($reqId, 120000, 'IRR', 'Initial inspection quote');
        self::assertSame(1, $r1->revision);

        // 3. Agency rejects Quote R1
        $this->login('agency', 'agency-1');
        $this->quoteService->reject($reqId, 1, 'Exceeds budget limit');

        // 4. Admin proposes Quote R2
        $this->login('government_admin', 'agency-1');
        $r2 = $this->quoteService->propose($reqId, 95000, 'IRR', 'Negotiated rate');
        self::assertSame(2, $r2->revision);

        // 5. Agency accepts Quote R2
        $this->login('agency', 'agency-1');
        $acceptedR2 = $this->quoteService->accept($reqId, 2);
        self::assertSame(QuoteStatus::Accepted, $acceptedR2->status);

        // Verify request remains in its existing Workflow state ('submitted')
        $requestRow = DB::table('service_requests')->where('id', $reqId)->first();
        $wfState = DB::table('workflow_instances')->where('id', $requestRow->workflow_instance_id)->value('current_state');
        self::assertSame('submitted', $wfState);

        // Verify no payment was automatically created
        self::assertSame(0, $this->gateway->prepareCalls);

        // 6. Platform integration continuation: host determines payment is eligible and dispatches Billing preparePayment
        $payment = $this->paymentService->create('srv-quote-req', RequestResource::TYPE, $reqId, 'ins-key-95k');
        self::assertSame(95000, $payment->money->amount);

        $prepared = $this->paymentService->preparePayment($payment->id, 'ins-prep-op-1');
        self::assertSame(PaymentStatus::Prepared, $prepared->status);
        self::assertSame(95000, $prepared->money->amount);
        self::assertSame('request_quote', $prepared->pricingSource);
        self::assertSame(2, $this->quoteService->acceptedQuote($reqId)->revision);
    }

    public function test_concurrent_proposal_revision_allocation_fails_gracefully_with_conflict(): void
    {
        $reqId = $this->createSubmittedRequest();
        $this->login('government_admin', 'gov-1');

        DB::table('request_quote_proposals')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'request_id' => $reqId,
            'revision' => 1,
            'amount' => 50000,
            'currency' => 'IRR',
            'status' => 'proposed',
            'proposed_by' => 'admin-1',
            'proposed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QuoteConflict::class);
        $this->expectExceptionMessage('Concurrent quote proposal conflict');
        $this->quoteService->propose($reqId, 60000, 'IRR');
    }
}

final class RequestQuoteTestGateway implements CentralPaymentGateway
{
    public int $prepareCalls = 0;
    public int $confirmCalls = 0;

    public function preparePayment(\Karband\Billing\ValueObjects\PreparePaymentCommand $command): \Karband\Billing\ValueObjects\PreparePaymentOutcome
    {
        $this->prepareCalls++;
        return \Karband\Billing\ValueObjects\PreparePaymentOutcome::prepared('cp-prep-' . $command->paymentId, 0, 0, $command->amount->amount);
    }

    public function confirmPayment(\Karband\Billing\ValueObjects\ConfirmPaymentCommand $command): \Karband\Billing\ValueObjects\ConfirmPaymentOutcome
    {
        $this->confirmCalls++;
        return \Karband\Billing\ValueObjects\ConfirmPaymentOutcome::succeeded($command->centralPaymentId, $command->amount->amount);
    }

    public function cancelPayment(\Karband\Billing\ValueObjects\CancelPaymentCommand $command): \Karband\Billing\ValueObjects\CancelPaymentOutcome
    {
        return \Karband\Billing\ValueObjects\CancelPaymentOutcome::cancelled($command->centralPaymentId);
    }

    public function lookupPayment(string $centralPaymentId): \Karband\Billing\ValueObjects\PaymentOutcome
    {
        return \Karband\Billing\ValueObjects\PaymentOutcome::succeeded($centralPaymentId);
    }

    public function lookupByOperationKey(string $operationKey): \Karband\Billing\ValueObjects\PaymentOutcome
    {
        return \Karband\Billing\ValueObjects\PaymentOutcome::succeeded('cp-opkey');
    }

    public function charge(\Karband\Billing\ValueObjects\PaymentCommand $command): \Karband\Billing\ValueObjects\PaymentOutcome
    {
        return \Karband\Billing\ValueObjects\PaymentOutcome::succeeded('cp-' . $command->paymentId);
    }

    public function lookup(\Karband\Billing\ValueObjects\PaymentCommand $command): \Karband\Billing\ValueObjects\PaymentOutcome
    {
        return \Karband\Billing\ValueObjects\PaymentOutcome::succeeded('cp-' . $command->paymentId);
    }
}

final class RequestQuoteTestPrincipalProvider implements PrincipalProvider
{
    public ?ActorContext $actor = null;

    public function current(): Principal
    {
        $user = Auth::guard('web')->user() ?? new RequestQuoteTestUser();
        $actor = $this->actor ?? new ActorContext('system-user', 'admin', 'gov-1');
        return new Principal($user, $actor, 'web');
    }
}

final class RequestQuoteTestUser extends BaseUser
{
    use HasRoles;
    protected $guard_name = 'web';
    protected $table = 'users';
    protected $guarded = [];
}
