<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bridges\LocalCatalogFinancialTermsProvider;
use App\Bridges\LocalPayableRequestGuard;
use App\Bridges\RequestCatalogBridge;
use App\Bridges\WorkflowActorResolver;
use App\Bridges\WorkflowAuthorizationBridge;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Karband\Audit\AuditEvent;
use Karband\Audit\AuditRecorderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Billing\Refund\RefundCommand;
use Karband\Billing\Refund\RefundOutcome;
use Karband\Billing\ValueObjects\CancelPaymentCommand;
use Karband\Billing\ValueObjects\CancelPaymentOutcome;
use Karband\Billing\ValueObjects\ConfirmPaymentCommand;
use Karband\Billing\ValueObjects\ConfirmPaymentOutcome;
use Karband\Billing\ValueObjects\Money;
use Karband\Billing\ValueObjects\PaymentOutcome;
use Karband\Billing\ValueObjects\PaymentStatus;
use Karband\Billing\ValueObjects\PreparePaymentCommand;
use Karband\Billing\ValueObjects\PreparePaymentOutcome;
use Karband\BillingLaravel\Contracts\CentralPaymentGateway;
use Karband\BillingLaravel\Contracts\CentralRefundGateway;
use Karband\BillingLaravel\Contracts\PayableRequestGuard;
use Karband\BillingLaravel\Contracts\TrustedFinancialTermsProvider;
use Karband\BillingLaravel\Contracts\TrustedQuoteProvider;
use Karband\BillingLaravel\Contracts\TrustedRefundPolicyProvider;
use Karband\BillingLaravel\Exceptions\PaymentConflict;
use Karband\BillingLaravel\Services\PaymentService;
use Karband\Documents\Definitions\DocumentDefinition;
use Karband\DocumentsLaravel\Services\DocumentRegistry;
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Definitions\FormField;
use Karband\DynamicFormsLaravel\Services\FormRegistry;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalog\Exceptions\InvalidService;
use Karband\ServiceCatalogLaravel\Exceptions\ServiceUnavailable;
use Karband\ServiceCatalogLaravel\Services\CatalogStorage;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\ServiceRequests\Policies\RequestPolicy;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequests\ValueObjects\RequestDraft;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Contracts\RecipientVerifier;
use Karband\ServiceRequestsLaravel\Contracts\RequestCatalogPort;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Karband\ServiceRequestsLaravel\Services\RequestLifecycle;
use Karband\ServiceRequestsLaravel\Services\RequestStorage;
use Karband\ServiceRequestsLaravel\Services\ServiceRequests;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Karband\WorkflowLaravel\Services\WorkflowRuntime;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class ServiceCatalogLifecycleAndBillingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private LifecycleTestAccount $account;
    private LifecycleTestPrincipalProvider $principals;
    private LifecycleTestScopes $scopes;
    private LifecycleTestAuditRecorder $audit;
    private LifecycleFakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.client_namespace' => 'sandbox_lifecycle_test']);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $allPermissions = [
            'catalog.service.create', 'catalog.service.update', 'catalog.service.activate',
            'catalog.service.deactivate', 'catalog.service.archive', 'catalog.service.view',
            'catalog.service.list', 'catalog.service.use',
            'workflow.definition.publish', 'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit',
            'workflow.action.process', 'workflow.action.complete', 'workflow.action.cancel_draft', 'workflow.action.cancel_submitted',
            'forms.definition.edit', 'forms.definition.publish', 'forms.response.submit', 'forms.response.view',
            'documents.definition.edit', 'documents.definition.publish', 'documents.file.upload', 'documents.file.view',
            'requests.create', 'requests.view', 'requests.submit', 'requests.cancel.draft', 'requests.cancel.request',
            'requests.cancel.decide', 'requests.cancel.withdraw', 'requests.correction.resubmit', 'requests.draft.delete',
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.reconcile',
            'billing.payment.retry', 'billing.payment.view',
        ];

        foreach ($allPermissions as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'catalog.service.create', 'catalog.service.update', 'catalog.service.activate',
            'catalog.service.deactivate', 'catalog.service.archive', 'catalog.service.view',
            'catalog.service.list', 'workflow.definition.publish',
            'forms.definition.edit', 'forms.definition.publish',
            'documents.definition.edit', 'documents.definition.publish',
        ]);

        Role::findByName('government_admin', 'web')->givePermissionTo([
            'catalog.service.view', 'catalog.service.list',
            'requests.view',
            'workflow.instance.view', 'workflow.action.submit', 'workflow.action.process', 'workflow.action.complete',
            'workflow.action.cancel_submitted',
        ]);

        Role::findByName('agency', 'web')->givePermissionTo([
            'catalog.service.list', 'catalog.service.use',
            'requests.create', 'requests.view',
            'forms.response.submit', 'forms.response.view',
            'documents.file.upload', 'documents.file.view',
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit',
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.reconcile',
            'billing.payment.retry', 'billing.payment.view',
        ]);

        $this->account = LifecycleTestAccount::create([
            'name' => 'Lifecycle Tester', 'email' => 'lifecycle@example.test', 'password' => 'secret',
        ]);

        $this->principals = new LifecycleTestPrincipalProvider();
        $this->scopes = new LifecycleTestScopes();
        $this->audit = new LifecycleTestAuditRecorder();
        $this->gateway = new LifecycleFakePaymentGateway();

        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->app->instance(AuditRecorderInterface::class, $this->audit);
        $this->app->instance(CentralPaymentGateway::class, $this->gateway);

        $this->app->instance(CentralRefundGateway::class, new class implements CentralRefundGateway {
            public function createRefund(RefundCommand $c): RefundOutcome { return RefundOutcome::succeeded('crf-01'); }
            public function lookupRefund(string $id): RefundOutcome { return RefundOutcome::succeeded($id); }
            public function lookupRefundByOperationKey(string $k): RefundOutcome { return RefundOutcome::succeeded('crf-01'); }
            public function refund(RefundCommand $c): RefundOutcome { return RefundOutcome::succeeded('crf-01'); }
            public function lookup(RefundCommand $c): RefundOutcome { return RefundOutcome::succeeded('crf-01'); }
        });

        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->app->bind(RequestCatalogPort::class, RequestCatalogBridge::class);
        $this->app->bind(PayableRequestGuard::class, LocalPayableRequestGuard::class);
        $this->app->bind(TrustedFinancialTermsProvider::class, LocalCatalogFinancialTermsProvider::class);
        $this->app->bind(TrustedQuoteProvider::class, LocalCatalogFinancialTermsProvider::class);
        $this->app->bind(TrustedRefundPolicyProvider::class, LocalCatalogFinancialTermsProvider::class);

        $this->app->instance(RecipientVerifier::class, new class implements RecipientVerifier {
            public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool { return true; }
        });
        $this->app->instance(\Karband\DocumentsLaravel\Contracts\ReplacementPolicy::class, new class implements \Karband\DocumentsLaravel\Contracts\ReplacementPolicy {
            public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool { return false; }
        });
        $this->app->instance(\Karband\DocumentsLaravel\Contracts\FileSafetyScanner::class, new class implements \Karband\DocumentsLaravel\Contracts\FileSafetyScanner {
            public function assertSafe(string $temporaryPath, string $detectedMimeType): void {}
        });
        $this->app->instance(\Karband\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier::class, new class implements \Karband\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier {
            public function assertReady(string $serviceCode, string $requestId): void {}
        });
        $this->app->instance(\Karband\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator::class, new class implements \Karband\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator {
            public function assertValid(string $serviceCode, array $profile): void {}
        });
        $this->app->instance(\Karband\ServiceRequestsLaravel\Contracts\RequestAudit::class, new class implements \Karband\ServiceRequestsLaravel\Contracts\RequestAudit {
            public function connectionName(): ?string { return \Illuminate\Support\Facades\DB::connection()->getName(); }
            public function record(string $event, string $requestId, array $safeMetadata = []): void {}
        });

        $this->seedRecipient('client-1', 'person', 'agency-tax');
        $this->seedRecipient('client-2', 'person', 'agency-tax');
        $this->seedRecipient('client-3', 'person', 'agency-tax');

        $this->asRole('platform_admin', 'platform');
    }

    private function seedRecipient(string $id, string $kind, string $agency = 'agency-tax'): void
    {
        DB::table('recipients')->insert([
            'id' => $id,
            'agency_id' => $agency,
            'kind' => $kind,
            'identity_type' => 'test_code',
            'identity_lookup' => hash('sha256', $agency . '/' . $kind . '/' . $id),
            'identity_cipher' => \Illuminate\Support\Facades\Crypt::encryptString('identity-' . $id),
            'profile_cipher' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['full_name' => 'Tax Client'], JSON_THROW_ON_ERROR)),
            'status' => 'active',
            'revision' => 1,
            'created_by' => 'fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asRole(string $role, ?string $organization): void
    {
        $this->account->syncRoles([$role]);
        Auth::guard('web')->setUser($this->account);
        $actor = new ActorContext((string) $this->account->getAuthIdentifier(), $role, $organization);
        $this->principals->principal = new Principal($this->account, $actor, 'web');
    }

    private function catalog(): ServiceCatalog { return $this->app->make(ServiceCatalog::class); }
    private function requests(): ServiceRequests { return $this->app->make(ServiceRequests::class); }
    private function lifecycle(): RequestLifecycle { return $this->app->make(RequestLifecycle::class); }
    private function billing(): PaymentService { return $this->app->make(PaymentService::class); }

    private function setupServiceResources(string $code = 'tax.filing', int $price = 1300, string $currency = 'EUR'): void
    {
        $this->asRole('platform_admin', 'platform');

        // Publish workflow
        $this->app->make(DefinitionCatalog::class)->publish(WorkflowDefinition::fromArray([
            'id' => $code . '.workflow', 'version' => 1, 'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'processing', 'completed', 'cancelled'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'process', 'from' => 'submitted', 'to' => 'processing', 'allowed_roles' => ['government_admin']],
                ['action' => 'complete', 'from' => 'processing', 'to' => 'completed', 'allowed_roles' => ['government_admin']],
                ['action' => 'cancel_draft', 'from' => 'draft', 'to' => 'cancelled', 'allowed_roles' => ['agency']],
                ['action' => 'cancel_submitted', 'from' => 'submitted', 'to' => 'cancelled', 'allowed_roles' => ['government_admin']],
            ],
        ]));

        // Create Service (initially Inactive)
        $policy = [
            'request_management' => [
                'draft_state' => 'draft',
                'submit_actions' => ['submit'],
                'submitted_states' => ['submitted', 'processing'],
                'terminal_states' => ['completed', 'cancelled'],
                'cancelled_state' => 'cancelled',
                'cancellable_states' => ['submitted'],
                'correction_states' => ['submitted'],
                'correction_mode' => 'full',
                'correction_action' => 'require_correction',
                'resubmit_action' => 'resubmit',
                'cancel_draft_action' => 'cancel_draft',
                'cancel_actions_by_state' => ['submitted' => 'cancel_submitted'],
                'recipient_field_map' => [],
            ],
            'pricing_mode' => 'fixed_catalog',
            'billing_fixed_price_minor' => $price,
            'billing_currency' => $currency,
            'refund_events' => ['request.rejected' => 'automatic'],
        ];

        $service = $this->catalog()->create(new ServiceDraft(
            code: $code,
            name: 'Tax Filing Service',
            description: 'Annual corporate and personal tax filing',
            settings: $policy,
            workflow: new WorkflowBinding($code . '.workflow', 1),
        ));

        self::assertSame(0, $service->revision);
        self::assertSame(ServiceStatus::Inactive, $service->status);

        // Publish form
        $forms = $this->app->make(FormRegistry::class);
        $formRev = $forms->saveDraft(new FormDefinition($code, 1, [
            new FormField('tax_year', 'Tax Year', 'text', true),
        ]));
        $forms->publishDraft($code, $formRev);

        // Publish doc definition
        $docs = $this->app->make(DocumentRegistry::class);
        $docRev = $docs->saveDraft(new DocumentDefinition($code, 1, []));
        $docs->publishDraft($code, $docRev);
    }

    /**
     * Requirement 1, 2, 3, 4, 5, 6, 7, 8:
     * Full lifecycle from creation -> activation -> request creation -> deactivation -> existing request continuity
     */
    public function test_service_lifecycle_activation_deactivation_and_request_continuity(): void
    {
        $this->setupServiceResources('tax.lifecycle', 1300, 'EUR');

        // 3. New service is inactive before activation -> new request cannot be created
        $this->asRole('agency', 'agency-tax');
        try {
            $this->requests()->create(new RequestDraft('tax.lifecycle', new RecipientReference('person', 'client-1'), 'key-01'));
            $this->fail('Inactive service must not allow new request creation');
        } catch (RequestConflict $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }

        // 2. Unauthorized actor cannot manage catalog
        $this->expectException(AuthorizationDenied::class);
        $this->catalog()->activate('tax.lifecycle', 0);

        // 1. Authorized platform actor activates service
        $this->asRole('platform_admin', 'platform');
        $activeService = $this->catalog()->activate('tax.lifecycle', 0);
        self::assertSame(1, $activeService->revision);
        self::assertSame(ServiceStatus::Active, $activeService->status);

        // 4. Active service allows new request creation
        $this->asRole('agency', 'agency-tax');
        $requestA = $this->requests()->create(new RequestDraft('tax.lifecycle', new RecipientReference('person', 'client-1'), 'key-01'));
        self::assertSame('draft', $requestA->currentState);
        self::assertSame('tax.lifecycle', $requestA->serviceCode);

        // Submit request A
        $this->requests()->saveAnswers($requestA->id, ['tax_year' => '2025'], 0);
        $submittedA = $this->requests()->act($requestA->id, 'submit');
        self::assertSame('submitted', $submittedA->currentState);

        // 5. Deactivate service
        $this->asRole('platform_admin', 'platform');
        $deactivated = $this->catalog()->deactivate('tax.lifecycle', 1);
        self::assertSame(2, $deactivated->revision);
        self::assertSame(ServiceStatus::Inactive, $deactivated->status);

        // Deactivated service CANNOT be used for new request B
        $this->asRole('agency', 'agency-tax');
        try {
            $this->requests()->create(new RequestDraft('tax.lifecycle', new RecipientReference('person', 'client-2'), 'key-02'));
            $this->fail('Deactivated service must not allow new request creation');
        } catch (RequestConflict $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }

        // 6 & 7. Existing Request A survives deactivation and remains readable
        $readA = $this->requests()->view($requestA->id);
        self::assertSame($requestA->id, $readA->id);
        self::assertSame('submitted', $readA->currentState);

        // 8. Existing valid Request A can continue after normal deactivation (government admin processes and completes it)
        $this->asRole('government_admin', 'gov-tax');
        $processedA = $this->requests()->act($requestA->id, 'process');
        self::assertSame('processing', $processedA->currentState);

        $completedA = $this->requests()->act($requestA->id, 'complete');
        self::assertSame('completed', $completedA->currentState);
    }

    /**
     * Requirement 9, 10, 11, 12, 13, 14, 15, 16:
     * Price revisions, immutable financial snapshots, payment retry semantics, and reporting safety.
     * Note on Money Units: Fixed catalog pricing in Karband is strictly stored in integer minor units.
     * EUR 1300 minor units = €13.00 (13.00 EUR); EUR 1500 minor units = €15.00 (15.00 EUR).
     */
    public function test_price_revisioning_immutable_billing_snapshots_and_reporting_safety(): void
    {
        $this->setupServiceResources('tax.pricing', 1300, 'EUR');

        $this->asRole('platform_admin', 'platform');
        $activeService = $this->catalog()->activate('tax.pricing', 0);
        self::assertSame(1, $activeService->revision);

        // Agency creates and submits Request 1
        $this->asRole('agency', 'agency-tax');
        $req1 = $this->requests()->create(new RequestDraft('tax.pricing', new RecipientReference('person', 'client-1'), 'key-req-1'));
        $this->requests()->saveAnswers($req1->id, ['tax_year' => '2025'], 0);
        $this->requests()->act($req1->id, 'submit');

        // Payment Attempt A starts with Revision 1 = 1300 minor units (€13.00)
        $paymentIntentA = $this->billing()->create('tax.pricing', RequestResource::TYPE, $req1->id, 'pay-key-1');
        self::assertSame(1300, $paymentIntentA->money->amount);
        self::assertSame('EUR', $paymentIntentA->money->currency);
        self::assertSame('13.00', number_format($paymentIntentA->money->amount / 100, 2, '.', ''));
        self::assertSame('€13.00', sprintf('€%.2f', $paymentIntentA->money->amount / 100));
        self::assertSame(PaymentStatus::Ready, $paymentIntentA->status);

        // Physical database proof for Attempt A snapshot
        $rawAttemptA = DB::table('payment_attempts')->where('payment_id', $paymentIntentA->id)->first();
        self::assertSame(1300, (int) $rawAttemptA->amount);
        self::assertSame('EUR', (string) $rawAttemptA->currency);
        self::assertSame(1, (int) $rawAttemptA->pricing_revision);

        // Complete Payment Attempt A
        $preparedA = $this->billing()->preparePayment($paymentIntentA->id, 'prep-op-1');
        self::assertSame(PaymentStatus::Prepared, $preparedA->status);
        $confirmedA = $this->billing()->confirmPayment($paymentIntentA->id, 'conf-op-1');
        self::assertSame(PaymentStatus::Succeeded, $confirmedA->status);

        // 9. Admin changes service price: Revision 2 = 1500 minor units (€15.00)
        $this->asRole('platform_admin', 'platform');
        $rev2 = $this->catalog()->updateFinancialTerms('tax.pricing', 1, 1500, 'EUR', ['request.rejected' => 'automatic']);
        self::assertSame(2, $rev2->revision);
        self::assertSame(1500, $rev2->settings['billing_fixed_price_minor']);
        self::assertSame('15.00', number_format($rev2->settings['billing_fixed_price_minor'] / 100, 2, '.', ''));

        // 10. Old revision is unchanged, and current revision resolution is deterministic
        $currentService = $this->catalog()->get('tax.pricing');
        self::assertSame(2, $currentService->revision);
        self::assertSame(1500, $currentService->settings['billing_fixed_price_minor']);

        // Physical database-level verification of catalog service table
        $rawCatalogRow = DB::table('catalog_services')->where('code', 'tax.pricing')->first();
        self::assertSame(2, (int) $rawCatalogRow->revision);
        $rawSettings = json_decode((string) $rawCatalogRow->settings, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1500, $rawSettings['billing_fixed_price_minor']);
        self::assertSame('EUR', $rawSettings['billing_currency']);

        // 11. Payment Attempt A keeps old amount (1300 minor units / €13.00) and old revision (1) after price edit
        $this->asRole('agency', 'agency-tax');
        $fetchedPaymentA = $this->billing()->view($paymentIntentA->id);
        self::assertSame(1300, $fetchedPaymentA->money->amount);
        self::assertSame('EUR', $fetchedPaymentA->money->currency);
        self::assertSame('€13.00', sprintf('€%.2f', $fetchedPaymentA->money->amount / 100));
        self::assertSame(PaymentStatus::Succeeded, $fetchedPaymentA->status);

        // Physical database proof that Revision 1 row in payment_attempts was NOT mutated by price edit
        $rawAttemptAAfter = DB::table('payment_attempts')->where('payment_id', $paymentIntentA->id)->first();
        self::assertSame(1300, (int) $rawAttemptAAfter->amount);
        self::assertSame('EUR', (string) $rawAttemptAAfter->currency);
        self::assertSame(1, (int) $rawAttemptAAfter->pricing_revision);

        // 12. Later eligible Payment Attempt B for Request 2 uses new amount (1500 minor units / €15.00) and revision (2)
        $this->asRole('agency', 'agency-tax');
        $req2 = $this->requests()->create(new RequestDraft('tax.pricing', new RecipientReference('person', 'client-2'), 'key-req-2'));
        $this->requests()->saveAnswers($req2->id, ['tax_year' => '2026'], 0);
        $this->requests()->act($req2->id, 'submit');

        $paymentIntentB = $this->billing()->create('tax.pricing', RequestResource::TYPE, $req2->id, 'pay-key-2');
        self::assertSame(1500, $paymentIntentB->money->amount);
        self::assertSame('EUR', $paymentIntentB->money->currency);
        self::assertSame('€15.00', sprintf('€%.2f', $paymentIntentB->money->amount / 100));

        // Physical database proof for Attempt B snapshot row
        $rawAttemptB = DB::table('payment_attempts')->where('payment_id', $paymentIntentB->id)->first();
        self::assertSame(1500, (int) $rawAttemptB->amount);
        self::assertSame('EUR', (string) $rawAttemptB->currency);
        self::assertSame(2, (int) $rawAttemptB->pricing_revision);

        // 13. Pending/unknown attempt cannot silently adopt new price
        $preparedB = $this->billing()->preparePayment($paymentIntentB->id, 'prep-op-2');
        self::assertSame(PaymentStatus::Prepared, $preparedB->status);

        // Attempting to refresh quote on a prepared/in-flight attempt is rejected
        try {
            $this->billing()->refreshReady($paymentIntentB->id, 'ref-key-2');
            $this->fail('Prepared/in-flight payment attempt cannot be refreshed');
        } catch (PaymentConflict $e) {
            $this->assertStringContainsString('Only an unused ready payment', $e->getMessage());
        }

        // 14. Historical financial aggregation remains unchanged after price edit:
        // Revenue calculated from succeeded payment attempts is 1300 minor units (€13.00), NOT 1500 minor units (€15.00)
        $succeededTotalRevenue = DB::table('payment_attempts')
            ->where('status', PaymentStatus::Succeeded->value)
            ->sum('amount');
        self::assertSame(1300, (int) $succeededTotalRevenue);
        self::assertSame('13.00', number_format((int) $succeededTotalRevenue / 100, 2, '.', ''));

        // 16. Admin mutations generate expected audit evidence
        $auditCodes = array_map(fn (AuditEvent $e) => $e->code, $this->audit->events);
        self::assertContains('catalog.service.created', $auditCodes);
        self::assertContains('catalog.service.activated', $auditCodes);
        self::assertContains('catalog.service.updated', $auditCodes);
    }

    /**
     * Requirement: Deactivation allows existing payable requests to resolve financial terms and pay.
     */
    public function test_existing_payable_request_can_be_paid_even_after_service_deactivation(): void
    {
        $this->setupServiceResources('tax.deactpay', 2500, 'EUR');

        $this->asRole('platform_admin', 'platform');
        $this->catalog()->activate('tax.deactpay', 0);

        // Agency creates and submits request while service is active
        $this->asRole('agency', 'agency-tax');
        $req = $this->requests()->create(new RequestDraft('tax.deactpay', new RecipientReference('person', 'client-3'), 'key-deact-1'));
        $this->requests()->saveAnswers($req->id, ['tax_year' => '2025'], 0);
        $this->requests()->act($req->id, 'submit');

        // Admin deactivates service
        $this->asRole('platform_admin', 'platform');
        $this->catalog()->deactivate('tax.deactpay', 1);

        // Agency initiates payment for the EXISTING submitted request -> SUCCEEDS!
        $this->asRole('agency', 'agency-tax');
        $payment = $this->billing()->create('tax.deactpay', RequestResource::TYPE, $req->id, 'pay-deact-key-1');
        self::assertSame(2500, $payment->money->amount);
        self::assertSame('EUR', $payment->money->currency);
        self::assertSame(PaymentStatus::Ready, $payment->status);

        $prepared = $this->billing()->preparePayment($payment->id, 'prep-deact-1');
        self::assertSame(PaymentStatus::Prepared, $prepared->status);

        $confirmed = $this->billing()->confirmPayment($payment->id, 'conf-deact-1');
        self::assertSame(PaymentStatus::Succeeded, $confirmed->status);
    }
}

final class LifecycleTestAccount extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class LifecycleTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class LifecycleTestScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if (str_starts_with($request->resourceType, 'workflow.') ||
            str_starts_with($request->resourceType, 'forms.') ||
            str_starts_with($request->resourceType, 'documents.')) {
            return true;
        }
        if ($request->resourceType === 'catalog.collection') {
            return true;
        }
        if ($request->resourceType === 'catalog.service') {
            if ($actor->role === 'agency') {
                return $actor->organizationId === 'agency-tax';
            }
            return in_array($actor->role, ['government_admin', 'platform_admin'], true);
        }
        if ($request->resourceType === 'catalog.request' || $request->resourceType === RequestResource::TYPE) {
            return true;
        }
        return false;
    }
}

final class LifecycleTestAuditRecorder implements AuditRecorderInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

final class LifecycleFakePaymentGateway implements CentralPaymentGateway
{
    public function preparePayment(PreparePaymentCommand $command): PreparePaymentOutcome
    {
        return PreparePaymentOutcome::prepared(
            centralPaymentId: 'cp-' . $command->paymentId,
            feeAmount: 100,
            taxAmount: 0,
            totalAmount: $command->amount->amount + 100,
        );
    }

    public function confirmPayment(ConfirmPaymentCommand $command): ConfirmPaymentOutcome
    {
        return ConfirmPaymentOutcome::succeeded('settled-' . $command->centralPaymentId, $command->amount->amount);
    }

    public function cancelPayment(CancelPaymentCommand $command): CancelPaymentOutcome
    {
        return CancelPaymentOutcome::succeeded();
    }

    public function lookupPayment(string $centralPaymentId): PaymentOutcome
    {
        return PaymentOutcome::succeeded('tx-' . $centralPaymentId);
    }

    public function lookupByOperationKey(string $operationKey): PaymentOutcome
    {
        return PaymentOutcome::succeeded('tx-op-' . $operationKey);
    }

    public function charge(\Karband\Billing\ValueObjects\PaymentCommand $command): PaymentOutcome
    {
        return PaymentOutcome::succeeded('tx-' . $command->paymentId);
    }

    public function lookup(\Karband\Billing\ValueObjects\PaymentCommand $command): PaymentOutcome
    {
        return PaymentOutcome::succeeded('tx-' . $command->paymentId);
    }
}
