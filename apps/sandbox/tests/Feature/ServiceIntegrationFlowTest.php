<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use App\Bridges\WorkflowActorResolver;
use App\Bridges\WorkflowAuthorizationBridge;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Billing\Refund\RefundPolicy;
use Karband\Billing\Refund\RefundInitiation;
use Karband\Billing\ValueObjects\Money;
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
use Karband\BillingLaravel\Contracts\TrustedQuoteProvider;
use Karband\BillingLaravel\Contracts\TrustedRefundPolicyProvider;
use Karband\BillingLaravel\Exceptions\PaymentConflict;
use Karband\BillingLaravel\Services\PaymentService;
use Karband\BillingLaravel\Services\TrustedPaymentFacts;
use Karband\Audit\AuditEvent;
use Karband\Audit\AuditRecorderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Documents\Definitions\DocumentDefinition;
use Karband\Documents\Definitions\DocumentRequirement;
use Karband\DocumentsLaravel\Contracts\FileSafetyScanner;
use Karband\DocumentsLaravel\Contracts\ReplacementPolicy;
use Karband\DocumentsLaravel\Services\DocumentRegistry;
use Karband\DocumentsLaravel\Services\TrustedDocumentFacts;
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Definitions\FormField;
use Karband\DynamicFormsLaravel\Services\FormRegistry;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequests\ValueObjects\RequestDraft;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Contracts\RecipientVerifier;
use Karband\ServiceRequestsLaravel\Contracts\RequestAudit;
use Karband\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier;
use Karband\ServiceRequestsLaravel\Services\RequestLifecycle;
use Karband\ServiceRequestsLaravel\Services\ServiceRequests;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Karband\Workflow\Contracts\TransitionGuard;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\Engine\StateMachine;
use Karband\Workflow\ValueObjects\GuardResult;
use Karband\Workflow\ValueObjects\TransitionContext;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\TrustedFactProvider;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

/**
 * Phase 17 reference-host fixtures for the Phase 13-15 contracts.
 * LOCAL SQLite + Storage::fake + FAKE CENTRAL PAYMENT. No provider credentials or actual customer PII.
 * These fixtures are NOT a production identity policy, malware scanner, price source or payment gateway.
 * This test is intentionally separate from the real host integration/production certification gate.
 */
final class ServiceIntegrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = 'phase12_benefit';
    private const RESOURCE = RequestResource::TYPE;
    private array $temporaryFiles = [];
    /** @var array<string,Phase17FixtureAccount> */
    private array $accounts = [];
    private Phase17FixturePrincipals $principals;
    private Phase17FixtureRecipients $recipients;
    private Phase17FixtureGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('documents.private_disk', 'local');
        config()->set('billing.client_namespace', 'phase17-local-service');
        config()->set('billing.reconcile_after_seconds', 0);
        config()->set('billing.max_attempts', 3);
        config()->set('audit.log_name', 'audit');
        config()->set('audit.allowed_events', array_values(array_unique(array_merge(
            (array) config('audit.allowed_events', []), [
                'requests.correction.requested', 'requests.correction.resubmitted',
                'requests.cancellation.requested', 'requests.cancellation.denied',
                'requests.cancellation.withdrawn', 'requests.cancellation.approved',
            ]
        ))));
        $permissions = [
            'workflow.definition.publish', 'workflow.instance.start', 'workflow.instance.view',
            'workflow.action.submit', 'workflow.action.resubmit', 'workflow.action.require_correction',
            'workflow.action.approve', 'workflow.action.mark_in_issuance',
            'workflow.action.cancel_draft', 'workflow.action.cancel_submitted', 'workflow.action.cancel_correction',
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.update', 'catalog.service.view', 'catalog.service.use',
            'forms.definition.edit', 'forms.definition.publish', 'forms.response.submit', 'forms.response.view',
            'documents.definition.edit', 'documents.definition.publish', 'documents.file.upload', 'documents.file.replace', 'documents.file.withdraw', 'documents.file.view',
            'requests.create', 'requests.view', 'requests.correction.issue', 'requests.correction.resubmit',
            'requests.cancel.request', 'requests.cancel.decide', 'requests.cancel.withdraw',
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.retry',
            'billing.payment.reconcile', 'billing.payment.view',
        ];
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'workflow.definition.publish', 'catalog.service.create', 'catalog.service.activate',
            'catalog.service.update', 'catalog.service.view', 'forms.definition.edit', 'forms.definition.publish',
            'documents.definition.edit', 'documents.definition.publish',
        ]);
        Role::findByName('agency', 'web')->givePermissionTo([
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit',
            'workflow.action.resubmit', 'catalog.service.use', 'forms.response.submit',
            'forms.response.view', 'documents.file.upload', 'documents.file.replace', 'documents.file.withdraw', 'documents.file.view',
            'requests.create', 'requests.view', 'requests.correction.resubmit',
            'requests.cancel.request', 'requests.cancel.withdraw',
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.retry',
            'billing.payment.reconcile', 'billing.payment.view',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'requests.view', 'requests.correction.issue', 'requests.cancel.decide',
            'workflow.instance.view', 'workflow.action.require_correction',
            'workflow.action.approve', 'workflow.action.cancel_submitted',
            'workflow.action.cancel_correction', 'workflow.action.mark_in_issuance',
            'forms.response.view', 'documents.file.upload', 'documents.file.replace', 'documents.file.view', 'billing.payment.view',
        ]);
        foreach (['platform_admin', 'government_admin', 'agency_a', 'agency_b'] as $name) {
            $role = str_starts_with($name, 'agency') ? 'agency' : $name;
            $this->accounts[$name] = Phase17FixtureAccount::create([
                'name' => 'Synthetic ' . $name,
                'email' => $name . '@phase17.example.test',
                'password' => 'test-only-unused',
            ]);
            $this->accounts[$name]->assignRole($role);
        }
        $this->principals = new Phase17FixturePrincipals();
        $this->recipients = new Phase17FixtureRecipients();
        $this->gateway = new Phase17FixtureGateway();
        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, new Phase17FixtureScopes());
        $this->app->instance(RecipientVerifier::class, $this->recipients);
        $this->app->instance(RequestAudit::class, new Phase17FixtureRequestAudit());
        $this->app->instance(RequestSubmissionVerifier::class, new Phase17FixtureSubmission());
        $this->app->instance(FileSafetyScanner::class, new Phase17FixtureScanner());
        $this->app->instance(ReplacementPolicy::class, new Phase17FixtureReplacement());
        $this->app->instance(TrustedQuoteProvider::class, new Phase17FixtureQuote());
        $this->app->instance(TrustedRefundPolicyProvider::class, new Phase17FixtureRefundPolicy());
        $this->app->instance(CentralPaymentGateway::class, $this->gateway);
        $this->app->instance(TrustedFactProvider::class, new Phase17FixtureFacts());
        $this->app->instance(StateMachine::class, new StateMachine([
            'request_complete' => new Phase17FixtureGuard(['form_ready', 'documents_complete']),
            'payment_confirmed' => new Phase17FixtureGuard(['payment_confirmed']),
        ]));
        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->seedLocalRecipient();
        $this->login('platform_admin');
        $this->publishReferenceService();
        $this->login('agency_a');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function seedLocalRecipient(): void
    {
        // A real encrypted LOCAL recipient row is required by Phase 15 lockActive().
        DB::table('recipients')->insert([
            'id' => 'recipient-001', 'agency_id' => 'agency-a', 'kind' => 'person',
            'identity_type' => 'fixture_id',
            'identity_lookup' => hash('sha256', 'synthetic-fixture-only'),
            'identity_cipher' => Crypt::encryptString('fixture-001'),
            'profile_cipher' => Crypt::encryptString(json_encode(['full_name' => 'Synthetic Person'], JSON_THROW_ON_ERROR)),
            'status' => 'active', 'revision' => 1, 'created_by' => 'fixture',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(string $account): void
    {
        $user = $this->accounts[$account];
        $role = str_starts_with($account, 'agency') ? 'agency' : $account;
        $organization = match ($account) {
            'agency_a' => 'agency-a', 'agency_b' => 'agency-b',
            'government_admin' => 'local-government', 'platform_admin' => 'local-platform',
        };
        Auth::guard('web')->setUser($user);
        $this->principals->principal = new Principal($user,
            new ActorContext((string) $user->getAuthIdentifier(), $role, $organization), 'web');
    }

    private function publishReferenceService(): void
    {
        app(DefinitionCatalog::class)->publish(WorkflowDefinition::fromArray([
            'id' => 'phase17.workflow', 'version' => 1, 'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'correction_required', 'approved', 'in_issuance', 'cancelled'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted',
                    'allowed_roles' => ['agency'], 'guards' => ['request_complete']],
                ['action' => 'require_correction', 'from' => 'submitted', 'to' => 'correction_required',
                    'allowed_roles' => ['government_admin'], 'comment_required' => true],
                ['action' => 'resubmit', 'from' => 'correction_required', 'to' => 'submitted',
                    'allowed_roles' => ['agency'], 'guards' => ['request_complete']],
                ['action' => 'approve', 'from' => 'submitted', 'to' => 'approved',
                    'allowed_roles' => ['government_admin'], 'guards' => ['request_complete']],
                ['action' => 'mark_in_issuance', 'from' => 'approved', 'to' => 'in_issuance',
                    'allowed_roles' => ['government_admin'], 'guards' => ['payment_confirmed']],
                ['action' => 'cancel_draft', 'from' => 'draft', 'to' => 'cancelled',
                    'allowed_roles' => ['agency']],
                ['action' => 'cancel_submitted', 'from' => 'submitted', 'to' => 'cancelled',
                    'allowed_roles' => ['government_admin']],
                ['action' => 'cancel_correction', 'from' => 'correction_required', 'to' => 'cancelled',
                    'allowed_roles' => ['government_admin']],
            ],
        ]));
        $catalog = app(ServiceCatalog::class);
        $service = $catalog->create(new ServiceDraft(self::SERVICE, 'Synthetic benefit', null, [
            'billing_fixed_price_minor' => 500,
            'billing_currency' => 'IRR',
            'refund_events' => ['cancellation_approved' => RefundInitiation::AdminApproval->value],
            'request_management' => [
                'draft_state' => 'draft', 'submit_actions' => ['submit', 'resubmit'],
                'submitted_states' => ['submitted', 'correction_required'],
                'terminal_states' => ['in_issuance', 'cancelled'], 'cancelled_state' => 'cancelled',
                'cancellable_states' => ['submitted', 'correction_required'],
                'correction_states' => ['correction_required'], 'correction_mode' => 'limited',
                'correction_action' => 'require_correction', 'resubmit_action' => 'resubmit',
                'cancel_draft_action' => 'cancel_draft',
                'cancel_actions_by_state' => [
                    'submitted' => 'cancel_submitted', 'correction_required' => 'cancel_correction',
                ],
                'recipient_field_map' => ['full_name' => 'name'],
            ],
        ], new WorkflowBinding('phase17.workflow', 1)));
        $forms = app(FormRegistry::class);
        $revision = $forms->saveDraft(new FormDefinition(self::SERVICE, 1, [
            new FormField('name', 'Full name', 'text', true, maxLength: 80),
            new FormField('reference', 'Opaque reference', 'text', true, maxLength: 40),
        ]));
        $forms->publishDraft(self::SERVICE, $revision);
        $documents = app(DocumentRegistry::class);
        $revision = $documents->saveDraft(new DocumentDefinition(self::SERVICE, 1, [
            new DocumentRequirement('evidence', 'Synthetic PDF', true, ['application/pdf'], 3000),
        ]));
        $documents->publishDraft(self::SERVICE, $revision);
        $catalog->activate(self::SERVICE, $service->revision);
    }

    private function requests(): ServiceRequests { return app(ServiceRequests::class); }
    private function lifecycle(): RequestLifecycle { return app(RequestLifecycle::class); }
    private function draft(string $recipient = 'recipient-001', string $key = 'create-001'): RequestDraft
    {
        return new RequestDraft(self::SERVICE, new RecipientReference('person', $recipient), $key);
    }
    private function syntheticPdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'phase17_pdf_');
        self::assertIsString($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, "%PDF-1.5\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        return new UploadedFile($path, 'sample.pdf', null, UPLOAD_ERR_OK, true);
    }
    private function submitCompleteRequest(string $key): string
    {
        $request = $this->requests()->create($this->draft(key: $key));
        $this->requests()->saveAnswers($request->id, [
            'name' => 'Synthetic Person', 'reference' => 'CASE-' . $key,
        ], 0);
        $this->requests()->uploadDocument($request->id, 'evidence', $this->syntheticPdf(), 0);
        self::assertTrue($this->requests()->act($request->id, 'submit', null, 'submit-' . $key)->applied);
        return $request->id;
    }

    public function test_full_flow_limited_correction_unknown_payment_then_reconcile_without_double_charge(): void
    {
        $request = DB::transaction(function () {
            $created = $this->requests()->create($this->draft());
            app(AuditRecorderInterface::class)->record(new AuditEvent(
                'requests.request.created', 'request', $created->id, ['service_code' => self::SERVICE]));
            return $created;
        });
        self::assertSame('draft', $request->currentState);
        self::assertSame([1, 1, 1], [$request->workflowVersion, $request->formVersion, $request->documentVersion]);
        self::assertFalse(Schema::hasColumn('service_requests', 'status'));
        $this->assertDatabaseHas('activity_log', ['event' => 'requests.request.created']);
        $this->assertDatabaseCount('payment_intents', 0);
        // In Phase 15, submission prerequisites are enforced BEFORE the state-machine call.
        try {
            $this->requests()->act($request->id, 'submit', null, 'premature-submit');
            self::fail('Incomplete form and document must prevent submission.');
        } catch (RequestConflict) {
            $this->assertDatabaseCount('workflow_history', 0);
        }
        $form = $this->requests()->saveAnswers($request->id,
            ['name' => 'Synthetic Person', 'reference' => 'CASE-001'], 0);
        self::assertSame(1, $form['revision']);
        $firstFile = $this->requests()->uploadDocument($request->id, 'evidence', $this->syntheticPdf(), 0);
        self::assertSame(1, $firstFile['slot_version']);
        self::assertTrue($this->requests()->act($request->id, 'submit', null, 'submit-001')->applied);
        self::assertSame('submitted', $this->requests()->view($request->id)->currentState);

        $this->login('government_admin');
        self::assertTrue($this->lifecycle()->requireCorrection($request->id,
            'Replace synthetic evidence', [], ['evidence'])->applied);
        app(AuditRecorderInterface::class)->record(new AuditEvent('workflow.transition.completed',
            'request', $request->id, ['from_state' => 'submitted', 'to_state' => 'correction_required']));

        $this->login('agency_a');
        try {
            $this->requests()->saveAnswers($request->id,
                ['name' => 'Incorrect overwrite', 'reference' => 'CASE-001'], 1);
            self::fail('Correction restricted to evidence must not permit form edit.');
        } catch (\DomainException|RequestConflict) {
            // No form change was committed.
        }
        $replacement = $this->requests()->uploadDocument($request->id, 'evidence', $this->syntheticPdf(), 1);
        self::assertSame(2, $replacement['slot_version']);
        $this->assertDatabaseCount('document_files', 2);
        self::assertTrue($this->lifecycle()->resubmit($request->id)->applied);
        $this->login('government_admin');
        self::assertTrue($this->requests()->act($request->id, 'approve', null, 'approve-001')->applied);
        $premature = $this->requests()->act($request->id, 'mark_in_issuance');
        self::assertFalse($premature->applied);

        $this->login('agency_a');
        $payment = $this->requests()->createPaymentIntent($request->id, 'payment-001');
        self::assertSame(500, $payment->money->amount);
        self::assertSame('IRR', $payment->money->currency);
        self::assertSame(PaymentStatus::Ready, $payment->status);
        $this->gateway->settleThenTimeout = true;
        $uncertain = app(PaymentService::class)->dispatch($payment->id,
            $payment->money->amount, $payment->money->currency, $payment->revision);
        self::assertSame(PaymentStatus::Unknown, $uncertain->status);
        app(AuditRecorderInterface::class)->record(new AuditEvent('billing.payment.unknown',
            'request', $request->id, ['operation_id' => $payment->id, 'result' => 'unknown']));
        try {
            $this->requests()->retryFailedPayment($request->id, $payment->id, 'illegal-retry');
            self::fail('An UNKNOWN payment cannot be retried or repriced.');
        } catch (PaymentConflict) {
            self::assertSame(1, $this->gateway->chargeCalls);
        }
        $confirmed = app(PaymentService::class)->reconcile($payment->id);
        self::assertSame(PaymentStatus::Succeeded, $confirmed->status);
        self::assertSame(1, $this->gateway->chargeCalls);
        self::assertSame(1, $this->gateway->lookupCalls);
        app(AuditRecorderInterface::class)->record(new AuditEvent('billing.payment.confirmed',
            'request', $request->id, ['operation_id' => $payment->id, 'result' => 'confirmed']));

        $this->login('government_admin');
        self::assertTrue($this->requests()->act($request->id, 'mark_in_issuance', null, 'issuance-001')->applied);
        self::assertSame('in_issuance', $this->requests()->view($request->id)->currentState);
        self::assertCount(5, $this->requests()->workflowHistory($request->id));
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        self::assertGreaterThanOrEqual(6, Activity::query()->where('log_name', 'audit')->count());
        self::assertSame('government_admin', Activity::query()->where('event', 'workflow.transition.completed')
            ->firstOrFail()->properties->get('actor_role'));
    }

    public function test_confirmed_no_debit_failure_refreshes_price_and_keeps_old_attempt_immutable(): void
    {
        $id = $this->submitCompleteRequest('retry-001');
        $this->login('government_admin');
        self::assertTrue($this->requests()->act($id, 'approve')->applied);
        $this->login('agency_a');
        $payment = $this->requests()->createPaymentIntent($id, 'first-quote');
        self::assertSame(500, $payment->money->amount);
        $this->gateway->definitiveNoDebit = true;
        $failed = app(PaymentService::class)->dispatch($payment->id,
            $payment->money->amount, $payment->money->currency, $payment->revision);
        self::assertSame(PaymentStatus::Failed, $failed->status);
        self::assertSame(1, $this->gateway->chargeCalls);
        $this->login('platform_admin');
        $catalog = app(ServiceCatalog::class);
        $definition = $catalog->get(self::SERVICE);
        $settings = $definition->settings;
        $settings['billing_fixed_price_minor'] = 750;
        $catalog->replace(self::SERVICE, $definition->revision,
            new ServiceDraft(self::SERVICE, $definition->name, $definition->description, $settings,
                new WorkflowBinding('phase17.workflow', 1)));
        $this->login('agency_a');
        $retry = $this->requests()->retryFailedPayment($id, $payment->id, 'new-quote-after-failed');
        self::assertSame(750, $retry->money->amount);
        self::assertSame(PaymentStatus::Ready, $retry->status);
        self::assertSame(2, $retry->attempt);
        $attempts = app(PaymentService::class)->attemptHistory($payment->id);
        self::assertSame([500, 750], array_map(static fn(object $a): int => (int) $a->amount, $attempts));
        self::assertSame(1, $this->gateway->chargeCalls); // second payment requires another explicit confirmation
        $this->gateway->definitiveNoDebit = false;
        $this->gateway->settleThenTimeout = true;
        $unknown = app(PaymentService::class)->dispatch($retry->id,
            $retry->money->amount, $retry->money->currency, $retry->revision);
        self::assertSame(PaymentStatus::Unknown, $unknown->status);
        self::assertSame(2, $this->gateway->chargeCalls);
        self::assertSame(PaymentStatus::Succeeded, app(PaymentService::class)->reconcile($retry->id)->status);
        $attempts = app(PaymentService::class)->attemptHistory($retry->id);
        self::assertSame([500, 750], array_map(static fn(object $a): int => (int) $a->amount, $attempts));
    }

    public function test_pending_cancellation_blocks_approval_then_denial_restores_original_workflow_state(): void
    {
        $id = $this->submitCompleteRequest('cancel-001');
        $pending = $this->lifecycle()->requestCancellation($id); // reason optional
        self::assertNotSame('', $pending);
        self::assertSame('submitted', $this->requests()->view($id)->currentState);
        $this->login('government_admin');
        try {
            $this->requests()->act($id, 'approve');
            self::fail('Approval must not bypass an agency cancellation request.');
        } catch (RequestConflict) {
            self::assertSame('submitted', $this->requests()->view($id)->currentState);
        }
        self::assertNull($this->lifecycle()->decideCancellation($id, false));
        self::assertSame('submitted', $this->requests()->view($id)->currentState);
        self::assertSame('denied', DB::table('request_cancellations')->where('id', $pending)->value('status'));
        $this->login('agency_a');
        $again = $this->lifecycle()->requestCancellation($id);
        $this->lifecycle()->withdrawCancellation($id);
        self::assertSame('withdrawn', DB::table('request_cancellations')->where('id', $again)->value('status'));
        self::assertSame('submitted', $this->requests()->view($id)->currentState);
        $this->assertDatabaseCount('payment_intents', 0);
    }

    public function test_cross_agency_access_is_denied_and_local_recipient_is_locked(): void
    {
        $request = $this->requests()->create($this->draft());
        $this->login('agency_b');
        try {
            $this->requests()->view($request->id);
            self::fail('Foreign agency must not read request.');
        } catch (AuthorizationDenied) {
            $this->assertDatabaseCount('workflow_history', 0);
        }
        $this->expectException(AuthorizationDenied::class);
        $this->requests()->create($this->draft('recipient-001', 'foreign-attempt'));
    }

    public function test_unavailable_or_archived_local_recipient_cannot_create_new_request(): void
    {
        $this->recipients->available = false;
        try {
            $this->requests()->create($this->draft());
            self::fail('A failing directory verifier must deny request creation.');
        } catch (AuthorizationDenied) {
            $this->assertDatabaseCount('service_requests', 0);
        }
        $this->recipients->available = true;
        DB::table('recipients')->where('id', 'recipient-001')->update(['status' => 'archived']);
        try {
            $this->requests()->create($this->draft(key: 'archived-attempt'));
            self::fail('A locally archived recipient cannot pass the row-locked profile gate.');
        } catch (AuthorizationDenied) {
            $this->assertDatabaseCount('workflow_instances', 0);
            $this->assertDatabaseCount('document_sets', 0);
        }
    }

    public function test_payment_cannot_start_before_approved_state(): void
    {
        $request = $this->requests()->create($this->draft());
        $this->expectException(\RuntimeException::class);
        try {
            $this->requests()->createPaymentIntent($request->id, 'early-payment');
        } finally {
            $this->assertDatabaseCount('payment_intents', 0);
            self::assertSame(0, $this->gateway->chargeCalls);
        }
    }

    public function test_audit_rejects_unapproved_personal_metadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditEvent('requests.request.created', 'request', 'opaque-1', ['customer_full_name' => 'Secret Name']);
    }
}

final class Phase17FixtureAccount extends BaseUser
{
    use HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class Phase17FixturePrincipals implements PrincipalProvider
{
    public ?Principal $principal = null;
    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('No trusted test principal.');
    }
}

/** This fixture mirrors the actual local request ownership, not a cross-service tenant lookup. */
final class Phase17FixtureScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($actor->role === 'platform_admin') {
            return in_array($request->resourceType, ['workflow.definition', 'catalog.service',
                'forms.definition', 'documents.definition'], true);
        }
        if ($request->resourceType === 'catalog.service') {
            return $request->resourceId === 'phase12_benefit' && $actor->role === 'agency'
                && $actor->organizationId === 'agency-a';
        }
        if ($request->resourceType !== RequestResource::TYPE) { return false; }
        $row = DB::table('service_requests')->where('id', $request->resourceId)->first();
        if ($row === null || $row->service_code !== 'phase12_benefit') { return false; }
        if ($actor->role === 'government_admin') {
            return $actor->organizationId === 'local-government';
        }
        return $actor->role === 'agency' && $actor->organizationId === (string) $row->agency_id;
    }
}

/** Extra host entitlement check. Phase 15 ALSO locks and validates the actual local recipient row. */
final class Phase17FixtureRecipients implements RecipientVerifier
{
    public bool $available = true;
    public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool
    {
        if (!$this->available) { throw new \RuntimeException('Test recipient verifier unavailable.'); }
        return $serviceCode === 'phase12_benefit' && $agencyId === 'agency-a'
            && $recipient->type === 'person' && $recipient->id === 'recipient-001';
    }
}

/** Mandatory Phase 15 audit writer sharing the local request DB; also exercises the real Spatie audit adapter. */
final class Phase17FixtureRequestAudit implements RequestAudit
{
    public function connectionName(): ?string { return DB::connection(config('service-requests.connection'))->getName(); }
    public function record(string $event, string $requestId, array $safeMetadata = []): void
    {
        $allowed = [];
        foreach ($safeMetadata as $key => $value) {
            $mapped = match ($key) {
                'round' => 'revision', 'history_id', 'cancellation_id' => 'operation_id',
                default => $key,
            };
            // All test lifecycle metadata is machine-only; reject any unexpected field rather than silently logging it.
            if (!in_array($mapped, ['revision', 'operation_id', 'service_code'], true)) {
                throw new \InvalidArgumentException('Unsafe request audit metadata in test host.');
            }
            $allowed[$mapped] = $value;
        }
        app(AuditRecorderInterface::class)->record(new AuditEvent($event, 'request', $requestId, $allowed));
    }
}

/** A non-permissive, DB-backed example of a host-specific prerequisite. */
final class Phase17FixtureSubmission implements RequestSubmissionVerifier
{
    public function assertReady(string $serviceCode, string $requestId): void
    {
        $row = DB::table('service_requests')->where('id', $requestId)->where('service_code', $serviceCode)->first();
        $recipient = $row === null ? null : DB::table('recipients')->where('id', $row->recipient_id)
            ->where('agency_id', $row->agency_id)->where('status', 'active')->first();
        if ($row === null || $recipient === null || $row->recipient_type !== 'person') {
            throw new RequestConflict('Synthetic local recipient requirement is not met.');
        }
        $answer = DB::table('form_answers')->where('resource_type', RequestResource::TYPE)
            ->where('resource_id', $requestId)->where('service_code', $serviceCode)->first();
        $values = $answer === null ? [] : json_decode((string) $answer->answers, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($values) || !is_string($values['reference'] ?? null)
            || !str_starts_with($values['reference'], 'CASE-')) {
            throw new RequestConflict('Synthetic reference must be a validated CASE token.');
        }
    }
}

/** This is NOT malware detection. It only checks the deliberately synthetic PDF fixture. */
final class Phase17FixtureScanner implements FileSafetyScanner
{
    public function assertSafe(string $temporaryPath, string $detectedMimeType): void
    {
        $head = file_get_contents($temporaryPath, false, null, 0, 5);
        if ($detectedMimeType !== 'application/pdf' || $head !== '%PDF-') {
            throw new \RuntimeException('Synthetic PDF fixture rejected.');
        }
    }
}

final class Phase17FixtureReplacement implements ReplacementPolicy
{
    public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool
    {
        $state = DB::table('service_requests as r')
            ->join('workflow_instances as w', 'w.id', '=', 'r.workflow_instance_id')
            ->where('r.id', $resourceId)->where('r.service_code', $serviceCode)
            ->where('r.agency_id', $actor->organizationId)->value('w.current_state');
        return $actor->role === 'agency' && $resourceType === RequestResource::TYPE
            && $slot === 'evidence' && $state === 'correction_required';
    }
}

final class Phase17FixtureFacts implements TrustedFactProvider
{
    public function forAction(WorkflowInstance $instance, string $action, \Karband\Workflow\ValueObjects\ActorContext $actor): array
    {
        $row = DB::table('service_requests')->where('id', $instance->resourceId)->first();
        if ($row === null || $instance->resourceType !== RequestResource::TYPE) {
            return ['form_ready' => false, 'documents_complete' => false, 'payment_confirmed' => false];
        }
        $base = ['service_code' => (string) $row->service_code,
            'resource_type' => RequestResource::TYPE, 'resource_id' => (string) $row->id];
        $answer = DB::table('form_answers')->where($base)->first();
        return [
            'form_ready' => $answer !== null && (int) $answer->revision > 0,
            'documents_complete' => app(TrustedDocumentFacts::class)
                ->isComplete((string) $row->service_code, RequestResource::TYPE, (string) $row->id),
            'payment_confirmed' => app(TrustedPaymentFacts::class)
                ->isConfirmed((string) $row->service_code, RequestResource::TYPE, (string) $row->id),
        ];
    }
}

final readonly class Phase17FixtureGuard implements TransitionGuard
{
    public function __construct(private array $keys) {}
    public function evaluate(TransitionContext $context): GuardResult
    {
        foreach ($this->keys as $key) {
            if (($context->facts[$key] ?? null) !== true) {
                return GuardResult::deny('missing_' . $key);
            }
        }
        return GuardResult::allow();
    }
}

/** Reads the LOCAL service catalog price, not a client-provided amount or central wallet. */
final class Phase17FixtureQuote implements TrustedQuoteProvider
{
    public function quote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): Money
    {
        $state = DB::table('service_requests as r')
            ->join('workflow_instances as w', 'w.id', '=', 'r.workflow_instance_id')
            ->where('r.id', $resourceId)->where('r.service_code', $serviceCode)
            ->where('r.agency_id', $agencyId)->value('w.current_state');
        if ($resourceType !== RequestResource::TYPE || $serviceCode !== 'phase12_benefit'
            || $state !== 'approved') {
            throw new \RuntimeException('Request is not approved and payable.');
        }
        $catalog = DB::table('catalog_services')->where('code', $serviceCode)->first();
        if ($catalog === null || $catalog->status !== 'active') {
            throw new \RuntimeException('Local catalog service unavailable for payment.');
        }
        $settings = json_decode((string) $catalog->settings, true, 512, JSON_THROW_ON_ERROR);
        $amount = $settings['billing_fixed_price_minor'] ?? null;
        $currency = $settings['billing_currency'] ?? null;
        if (!is_int($amount) || $amount <= 0 || !is_string($currency)) {
            throw new \RuntimeException('Missing trusted local fixed-price configuration.');
        }
        return new Money($amount, $currency);
    }
}

/** Refund policy is also read from the authoritative LOCAL service catalog. */
final class Phase17FixtureRefundPolicy implements TrustedRefundPolicyProvider
{
    public function forPayment(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): RefundPolicy
    {
        $owner = DB::table('service_requests')->where('service_code', $serviceCode)
            ->where('id', $resourceId)->where('agency_id', $agencyId)->exists();
        if (!$owner || $resourceType !== RequestResource::TYPE) {
            throw new \RuntimeException('Untrusted refund policy resource.');
        }
        $catalog = DB::table('catalog_services')->where('code', $serviceCode)->first();
        if ($catalog === null || $catalog->status !== 'active') {
            throw new \RuntimeException('Service is not available for policy lookup.');
        }
        $settings = json_decode((string) $catalog->settings, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings['refund_events'] ?? null)) {
            throw new \RuntimeException('Missing host refund policy.');
        }
        return RefundPolicy::fromArray($settings['refund_events']);
    }
}

/** Deterministic FAKE CENTRAL API: timeout/failed outcomes are explicitly controlled by each test. */
final class Phase17FixtureGateway implements CentralPaymentGateway
{
    public int $chargeCalls = 0;
    public int $lookupCalls = 0;
    public bool $settleThenTimeout = false;
    public bool $definitiveNoDebit = false;
    private array $remote = [];

    public function preparePayment(PreparePaymentCommand $command): PreparePaymentOutcome
    {
        return PreparePaymentOutcome::prepared('synthetic-prep-' . $command->paymentId, 0, 0, $command->amount->amount);
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
        $this->lookupCalls++;
        return PaymentOutcome::succeeded($centralPaymentId);
    }

    public function lookupByOperationKey(string $operationKey): PaymentOutcome
    {
        $this->lookupCalls++;
        return PaymentOutcome::succeeded('synthetic-by-opkey');
    }

    public function charge(PaymentCommand $command): PaymentOutcome
    {
        $this->chargeCalls++;
        if (isset($this->remote[$command->requestKey])) {
            return $this->remote[$command->requestKey];
        }
        if ($this->definitiveNoDebit) {
            return $this->remote[$command->requestKey] = PaymentOutcome::failed('synthetic-no-debit');
        }
        if ($this->settleThenTimeout) {
            $this->remote[$command->requestKey] = PaymentOutcome::succeeded('synthetic-central-001');
            throw new \RuntimeException('Simulated lost response after settlement.');
        }
        return $this->remote[$command->requestKey] = PaymentOutcome::pending('synthetic-pending-001');
    }
    public function lookup(PaymentCommand $command): PaymentOutcome
    {
        $this->lookupCalls++;
        return $this->remote[$command->requestKey] ?? PaymentOutcome::unknown();
    }
}
