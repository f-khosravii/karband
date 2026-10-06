<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Billing\Refund\RefundPolicy;
use Karband\Billing\ValueObjects\Money;
use Karband\Billing\ValueObjects\PaymentStatus;
use Karband\BillingLaravel\Contracts\CentralPaymentGateway;
use Karband\BillingLaravel\Contracts\CentralRefundGateway;
use Karband\BillingLaravel\Contracts\PayableRequestGuard;
use Karband\BillingLaravel\Services\PaymentService;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Authorization\AuthorizerInterface;
use Karband\Documents\Definitions\DocumentDefinition;
use Karband\Documents\Definitions\DocumentRequirement;
use Karband\DocumentsLaravel\Services\DocumentManager;
use Karband\DocumentsLaravel\Services\DocumentRegistry;
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Definitions\FormField;
use Karband\DynamicForms\Definitions\FormSection;
use Karband\DynamicForms\Definitions\RepeatableGroupDefinition;
use Karband\DynamicFormsLaravel\Services\FormAnswers;
use Karband\DynamicFormsLaravel\Services\FormRegistry;
use Karband\RequestJourney\Definitions\JourneyDefinition;
use Karband\RequestJourney\Definitions\NavigationMode;
use Karband\RequestJourney\Definitions\StepDefinition;
use Karband\RequestJourney\Definitions\StepState;
use Karband\RequestJourney\Definitions\StepType;
use Karband\RequestJourneyLaravel\Contracts\JourneyRegistryInterface;
use Karband\RequestJourneyLaravel\Contracts\JourneyServiceInterface;
use Karband\RequestJourneyLaravel\Exceptions\JourneyLockedStep;
use Karband\RequestJourneyLaravel\Exceptions\JourneyNotFound;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalog\Financial\PricingMode;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequests\ValueObjects\RequestDraft;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Services\RequestQuoteService;
use Karband\ServiceRequestsLaravel\Services\ServiceRequests;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class JourneyTestAccount extends BaseUser
{
    use HasRoles;

    protected $guard_name = 'web';
    protected $table = 'users';
    protected $guarded = [];
}

final class JourneyTestPrincipalProvider implements PrincipalProvider
{
    public ?ActorContext $override = null;

    public function current(): Principal
    {
        $user = Auth::guard('web')->user() ?? new JourneyTestAccount([
            'id' => 999,
            'name' => 'Guest',
            'email' => 'guest@intl.test',
            'role' => 'guest',
            'organization_id' => null,
        ]);
        $actor = $this->override ?? new ActorContext(
            id: (string) ($user->id ?? 999),
            role: (string) ($user->role ?? 'guest'),
            organizationId: $user->organization_id !== null ? (string) $user->organization_id : null,
        );
        return new Principal($user, $actor, 'web');
    }
}

final class JourneyTestDocumentStatePolicy implements \Karband\DocumentsLaravel\Contracts\DocumentStatePolicy
{
    public function allows(
        ActorContext $actor,
        string $action,
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        string $currentState
    ): bool {
        return true;
    }
}

final class JourneyTestScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($request->resourceType === 'service_request' && $request->resourceId !== null) {
            $req = DB::table('service_requests')->where('id', $request->resourceId)->first();
            if ($req !== null && $actor->role === 'agency' && $actor->organizationId !== null) {
                return (string) $req->agency_id === (string) $actor->organizationId;
            }
        }
        return true;
    }
}

final class JourneyTestPaymentGateway implements CentralPaymentGateway
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

final class JourneyTestRefundGateway implements CentralRefundGateway
{
    public function createRefund(\Karband\Billing\Refund\RefundCommand $command): \Karband\Billing\Refund\RefundOutcome
    {
        return \Karband\Billing\Refund\RefundOutcome::succeeded('cr-' . $command->paymentId, $command->amount->amount);
    }

    public function lookupRefund(string $centralRefundId): \Karband\Billing\Refund\RefundOutcome
    {
        return \Karband\Billing\Refund\RefundOutcome::succeeded($centralRefundId);
    }

    public function lookupRefundByOperationKey(string $operationKey): \Karband\Billing\Refund\RefundOutcome
    {
        return \Karband\Billing\Refund\RefundOutcome::succeeded('cr-opkey');
    }

    public function refund(\Karband\Billing\Refund\RefundCommand $command): \Karband\Billing\Refund\RefundOutcome
    {
        return \Karband\Billing\Refund\RefundOutcome::succeeded('cr-' . $command->paymentId, $command->amount->amount);
    }

    public function lookup(\Karband\Billing\Refund\RefundCommand $command): \Karband\Billing\Refund\RefundOutcome
    {
        return \Karband\Billing\Refund\RefundOutcome::succeeded('cr-' . $command->paymentId);
    }
}

final class RequestJourneyCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private JourneyTestPrincipalProvider $principals;
    private JourneyTestPaymentGateway $paymentGateway;
    private JourneyTestRefundGateway $refundGateway;
    private JourneyRegistryInterface $journeyRegistry;
    private JourneyServiceInterface $journeyService;
    private ServiceRequests $requestsService;
    private RequestQuoteService $quoteService;
    private PaymentService $paymentService;
    private FormAnswers $forms;
    private DocumentManager $documents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }

        $allPermissions = [
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.view', 'catalog.service.use',
            'workflow.definition.publish', 'workflow.instance.start', 'workflow.instance.view',
            'workflow.action.submit', 'workflow.action.complete', 'workflow.action.propose_quote', 'workflow.action.accept_quote', 'workflow.action.activate',
            'forms.definition.edit', 'forms.definition.view', 'forms.definition.publish',
            'forms.response.submit', 'forms.response.view',
            'documents.definition.edit', 'documents.definition.view', 'documents.definition.publish',
            'documents.file.upload', 'documents.file.read', 'documents.file.download', 'documents.file.view', 'documents.set.reserve',
            'requests.create', 'requests.view', 'requests.submit', 'requests.cancel',
            'quote.propose', 'quote.accept', 'quote.reject', 'quote.view',
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.view',
        ];

        foreach ($allPermissions as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        Role::findByName('platform_admin', 'web')->givePermissionTo($allPermissions);
        Role::findByName('government_admin', 'web')->givePermissionTo($allPermissions);
        Role::findByName('agency', 'web')->givePermissionTo([
            'catalog.service.view', 'catalog.service.use',
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit', 'workflow.action.accept_quote',
            'forms.response.submit', 'forms.response.view',
            'documents.file.upload', 'documents.file.read', 'documents.file.download', 'documents.file.view', 'documents.set.reserve',
            'requests.create', 'requests.view', 'requests.submit', 'requests.cancel',
            'quote.accept', 'quote.reject', 'quote.view',
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.view',
        ]);

        $this->principals = new JourneyTestPrincipalProvider();
        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, new JourneyTestScopes());

        $this->paymentGateway = new JourneyTestPaymentGateway();
        $this->refundGateway = new JourneyTestRefundGateway();
        $this->app->instance(CentralPaymentGateway::class, $this->paymentGateway);
        $this->app->instance(CentralRefundGateway::class, $this->refundGateway);
        $this->app->instance(\Karband\DocumentsLaravel\Contracts\DocumentStatePolicy::class, new JourneyTestDocumentStatePolicy());

        config(['billing.client_namespace' => 'sandbox_journey_test']);
        config(['audit.allowed_events' => array_merge(config('audit.allowed_events', []), [
            'request.quote.proposed', 'request.quote.accepted', 'request.quote.rejected',
        ])]);

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

        $this->quoteService = $this->app->make(RequestQuoteService::class);
        $this->requestsService = $this->app->make(ServiceRequests::class);

        $payableGuard = new class implements PayableRequestGuard {
            public function connectionName(): string { return \Illuminate\Support\Facades\DB::connection()->getName(); }
            public function assertPayable(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): void {
                $exists = DB::table('service_requests')->where('id', $resourceId)->where('agency_id', $agencyId)->exists();
                if (!$exists) { throw new \RuntimeException('Request not payable'); }
            }
        };

        $termsProvider = new \App\Bridges\LocalCatalogFinancialTermsProvider(
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
            $this->paymentGateway,
            new \Karband\Billing\Engine\PaymentStateMachine(),
            $termsProvider
        );
        $this->app->instance(PaymentService::class, $this->paymentService);

        $this->journeyRegistry = $this->app->make(JourneyRegistryInterface::class);
        $this->journeyService = $this->app->make(JourneyServiceInterface::class);
        $this->forms = $this->app->make(FormAnswers::class);
        $this->documents = $this->app->make(DocumentManager::class);
    }

    private array $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function pdf(string $name = 'test.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'platform_journey_test_');
        $this->temps[] = $path;
        file_put_contents($path, "%PDF-1.5\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public function test_journey_definition_registry_and_version_pinning(): void
    {
        $this->asPlatformAdmin();
        $this->seedService('srv_ver', 'workflow_ver');

        $journeyV1 = new JourneyDefinition('srv_ver', 1, NavigationMode::LINEAR, [
            new StepDefinition('form_step', StepType::FORM, 'Form Details'),
            new StepDefinition('doc_step', StepType::DOCUMENTS, 'Documents'),
        ]);
        $this->journeyRegistry->publish($journeyV1, 'admin_1');

        self::assertTrue($this->journeyRegistry->has('srv_ver', 1));
        self::assertSame(1, $this->journeyRegistry->latestVersion('srv_ver'));
        self::assertSame('form_step', $this->journeyRegistry->get('srv_ver', 1)?->steps[0]->key);

        // 1. Create request 1 -> pinned to Journey v1
        $this->asAgency('agency_a');
        $this->seedRecipient('rec_1', 'agency_a');
        $req1 = $this->requestsService->create(new RequestDraft('srv_ver', new RecipientReference('person', 'rec_1'), 'idem_v1'));

        $prog1 = $this->journeyService->progress($req1->id);
        self::assertNotNull($prog1);
        self::assertSame(1, $prog1->journeyVersion);
        self::assertCount(2, $prog1->steps);

        // 2. Publish Journey v2
        $this->asPlatformAdmin();
        $journeyV2 = new JourneyDefinition('srv_ver', 2, NavigationMode::LINEAR, [
            new StepDefinition('form_step', StepType::FORM, 'Form Details'),
            new StepDefinition('doc_step', StepType::DOCUMENTS, 'Documents'),
            new StepDefinition('review_step', StepType::REVIEW, 'Review'),
        ]);
        $this->journeyRegistry->publish($journeyV2, 'admin_1');
        self::assertSame(2, $this->journeyRegistry->latestVersion('srv_ver'));

        // 3. Existing request 1 remains pinned to Journey v1
        $this->asAgency('agency_a');
        $prog1After = $this->journeyService->progress($req1->id);
        self::assertSame(1, $prog1After->journeyVersion);
        self::assertCount(2, $prog1After->steps);

        // 4. New request 2 gets pinned to Journey v2
        $this->seedRecipient('rec_2', 'agency_a');
        $req2 = $this->requestsService->create(new RequestDraft('srv_ver', new RecipientReference('person', 'rec_2'), 'idem_v2'));
        $prog2 = $this->journeyService->progress($req2->id);
        self::assertSame(2, $prog2->journeyVersion);
        self::assertCount(3, $prog2->steps);
    }

    public function test_ncr_free_navigation_scenario_with_dynamic_forms_v2_and_documents_v2(): void
    {
        $this->asPlatformAdmin();

        // 1. Setup NCR service with Sections & Repeatable Groups in FormDefinition
        $serviceCode = 'ncr_service';
        $this->seedNcrService($serviceCode);

        // 2. Define Free Navigation Journey: personal, family, documents, review
        $ncrJourney = new JourneyDefinition($serviceCode, 1, NavigationMode::FREE, [
            new StepDefinition('personal', StepType::FORM, 'Personal Details', 'sec_personal'),
            new StepDefinition('family', StepType::FORM, 'Family Members', 'sec_family'),
            new StepDefinition('documents', StepType::DOCUMENTS, 'Required Documents'),
            new StepDefinition('review', StepType::REVIEW, 'Review Application', prerequisites: ['personal', 'family', 'documents']),
        ]);
        $this->journeyRegistry->publish($ncrJourney, 'admin_1');

        // 3. Agency creates draft
        $this->asAgency('agency_ncr');
        $this->seedRecipient('rec_ncr', 'agency_ncr');
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_ncr'), 'idem_ncr_1'));

        // Initial Progress
        $prog0 = $this->journeyService->progress($req->id);
        self::assertNotNull($prog0);
        self::assertSame(NavigationMode::FREE, $prog0->navigationMode);
        self::assertSame(StepState::CURRENT, $prog0->step('personal')?->state);
        self::assertSame(StepState::AVAILABLE, $prog0->step('family')?->state); // Free mode: sibling available
        self::assertSame(StepState::AVAILABLE, $prog0->step('documents')?->state); // Free mode: available
        self::assertSame(StepState::LOCKED, $prog0->step('review')?->state); // Locked because prerequisites incomplete

        // 4. User jumps directly to visit 'family' first (allowed in Free mode)
        $this->journeyService->visit($req->id, 'family');
        $prog1 = $this->journeyService->progress($req->id);
        self::assertSame('family', $prog1->currentStepKey);
        self::assertSame(StepState::CURRENT, $prog1->step('family')?->state);
        self::assertSame(StepState::AVAILABLE, $prog1->step('personal')?->state);
        // Note: Visiting family did NOT complete it!
        self::assertFalse($prog1->step('family')?->isCompleted());

        // 5. Fill section 'personal'
        $this->forms->save($serviceCode, RequestResource::TYPE, $req->id, [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ], 0);

        $prog2 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $prog2->step('personal')?->state);
        self::assertSame(StepState::CURRENT, $prog2->step('family')?->state);
        self::assertSame(StepState::LOCKED, $prog2->step('review')?->state);

        // 6. Fill repeatable group in section 'family'
        $this->forms->save($serviceCode, RequestResource::TYPE, $req->id, [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'members' => [
                ['_item_id' => 'm1', 'name' => 'Jane Doe', 'relation' => 'Spouse'],
            ],
        ], 1);

        $prog3 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $prog3->step('personal')?->state);
        self::assertSame(StepState::COMPLETED, $prog3->step('family')?->state);
        self::assertSame(StepState::LOCKED, $prog3->step('review')?->state); // documents still missing

        // 7. Upload required document via Documents v2
        $file = $this->pdf('id_card.pdf');
        $this->documents->upload($serviceCode, RequestResource::TYPE, $req->id, 'national_id', $file, 0);

        // 8. Review step prerequisites are now satisfied!
        $prog4 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $prog4->step('personal')?->state);
        self::assertSame(StepState::COMPLETED, $prog4->step('family')?->state);
        self::assertSame(StepState::COMPLETED, $prog4->step('documents')?->state);
        self::assertSame(StepState::COMPLETED, $prog4->step('review')?->state);
        self::assertSame(100.0, $prog4->percentageComplete);

        // 9. Verify visit did not mutate Workflow state (remains in draft)
        $this->journeyService->visit($req->id, 'review');
        $wfInstance = DB::table('workflow_instances')->where('resource_id', $req->id)->first();
        self::assertSame('draft', $wfInstance->current_state);
    }

    public function test_insurance_linear_lifecycle_scenario_with_quote_signature_billing_and_documents(): void
    {
        $this->asPlatformAdmin();

        $serviceCode = 'ins_service';
        $this->seedInsuranceService($serviceCode);

        // Linear Lifecycle Journey: setup -> documents -> quote -> signature -> payment -> final_policy
        $insJourney = new JourneyDefinition($serviceCode, 1, NavigationMode::LINEAR, [
            new StepDefinition('setup', StepType::FORM, 'Policy Setup'),
            new StepDefinition('documents', StepType::DOCUMENTS, 'Vehicle Documents'),
            new StepDefinition('quote', StepType::QUOTE, 'Quote Acceptance', availableWhenWorkflowStates: ['quoted', 'underwriting', 'active']),
            new StepDefinition('signature', StepType::SIGNATURE, 'Signed Document', prerequisites: ['quote']),
            new StepDefinition('payment', StepType::PAYMENT, 'Premium Payment', prerequisites: ['signature']),
            new StepDefinition('final_policy', StepType::CUSTOM, 'Final Policy Issue', prerequisites: ['payment'], customFactKey: 'policy_issued'),
        ]);
        $this->journeyRegistry->publish($insJourney, 'admin_1');

        // 1. Agency creates draft
        $this->asAgency('agency_ins');
        $this->seedRecipient('rec_ins', 'agency_ins');
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_ins'), 'idem_ins_1'));

        // Step 1 (setup) is current; all others locked
        $p1 = $this->journeyService->progress($req->id);
        self::assertSame('setup', $p1->currentStepKey);
        self::assertSame(StepState::CURRENT, $p1->step('setup')?->state);
        self::assertSame(StepState::LOCKED, $p1->step('documents')?->state);
        self::assertSame(StepState::LOCKED, $p1->step('quote')?->state);

        // 2. Complete setup form
        $this->forms->save($serviceCode, RequestResource::TYPE, $req->id, [
            'vehicle_model' => 'Sedan',
            'coverage_amount' => 50000,
        ], 0);

        $p2 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $p2->step('setup')?->state);
        self::assertSame(StepState::CURRENT, $p2->step('documents')?->state);
        self::assertSame(StepState::LOCKED, $p2->step('quote')?->state);

        // 3. Upload vehicle registration doc
        $file = $this->pdf('reg.pdf');
        $this->documents->upload($serviceCode, RequestResource::TYPE, $req->id, 'reg_doc', $file, 0);

        $p3 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $p3->step('documents')?->state);
        // Note: quote step is locked by workflow state constraint (still in draft, needs 'quoted')
        self::assertSame(StepState::LOCKED, $p3->step('quote')?->state);

        // 4. Government admin moves workflow to 'quoted' and proposes quote
        $this->asGovernmentAdmin('gov_org');
        DB::table('workflow_instances')->where('resource_id', $req->id)->update(['current_state' => 'quoted']);
        $proposal = $this->quoteService->propose($req->id, 120000, 'USD', 'Standard rate');

        // Agency views journey: quote step is now AVAILABLE / CURRENT!
        $this->asAgency('agency_ins');
        $p4 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::CURRENT, $p4->step('quote')?->state);
        self::assertSame(StepState::LOCKED, $p4->step('signature')?->state);

        // 5. Agency visits 'quote' step -> NO side effects (does NOT accept quote)
        $this->journeyService->visit($req->id, 'quote');
        $qRow = DB::table('request_quotes')->where('request_id', $req->id)->first();
        self::assertNull($qRow->accepted_revision);

        // 6. Agency explicitly accepts quote via RequestQuoteService
        $this->quoteService->accept($req->id, $proposal->revision);

        // Journey reflects quote step COMPLETED; agency visits signature step which becomes CURRENT
        $this->journeyService->visit($req->id, 'signature');
        $p5 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $p5->step('quote')?->state);
        self::assertSame(StepState::CURRENT, $p5->step('signature')?->state);
        self::assertSame('signature', $p5->currentStepKey);
        self::assertSame(StepState::LOCKED, $p5->step('payment')?->state);

        // 7. Agency uploads signed proposal document (fulfilling signature fact)
        $signedFile = $this->pdf('signed.pdf');
        $this->documents->upload($serviceCode, RequestResource::TYPE, $req->id, 'signed_proposal', $signedFile, 1);

        $this->journeyService->visit($req->id, 'payment');
        $p6 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $p6->step('signature')?->state);
        self::assertSame(StepState::CURRENT, $p6->step('payment')?->state);
        self::assertSame('payment', $p6->currentStepKey);
        self::assertSame(StepState::LOCKED, $p6->step('final_policy')?->state);

        // 8. Agency visits payment step -> NO payment created or confirmed!
        $this->journeyService->visit($req->id, 'payment');
        self::assertFalse(DB::table('payment_intents')->where('resource_id', $req->id)->exists());

        // 9. Agency processes payment through PaymentService (Billing V2)
        $intent = $this->paymentService->create($serviceCode, RequestResource::TYPE, $req->id, 'pay_key_1');
        $this->paymentService->preparePayment($intent->id, 'prep_op_1');
        $this->paymentService->confirmPayment($intent->id, 'conf_op_1', 'c_hold_' . $intent->id);

        // Journey reflects payment step COMPLETED; agency visits final_policy which becomes CURRENT
        $this->journeyService->visit($req->id, 'final_policy');
        $p7 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $p7->step('payment')?->state);
        self::assertSame(StepState::CURRENT, $p7->step('final_policy')?->state);
        self::assertSame('final_policy', $p7->currentStepKey);
    }

    public function test_no_charge_pricing_mode_skips_payment_step(): void
    {
        $this->asPlatformAdmin();

        $serviceCode = 'free_service';
        $this->seedService($serviceCode, 'wf_free', PricingMode::NoCharge);

        $journey = new JourneyDefinition($serviceCode, 1, NavigationMode::LINEAR, [
            new StepDefinition('form_step', StepType::FORM, 'Application Form'),
            new StepDefinition('payment_step', StepType::PAYMENT, 'Fee Payment', skipWhenPricingModes: ['no_charge']),
            new StepDefinition('final_step', StepType::CUSTOM, 'Confirmation', customFactKey: 'confirmed'),
        ]);
        $this->journeyRegistry->publish($journey, 'admin_1');

        $this->asAgency('agency_free');
        $this->seedRecipient('rec_free', 'agency_free');
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_free'), 'idem_free_1'));

        $this->forms->save($serviceCode, RequestResource::TYPE, $req->id, ['field_1' => 'val'], 0);

        $prog = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $prog->step('form_step')?->state);
        self::assertSame(StepState::SKIPPED, $prog->step('payment_step')?->state);
        self::assertSame(StepState::CURRENT, $prog->step('final_step')?->state);
        self::assertSame('final_step', $prog->currentStepKey);
        self::assertSame(50.0, $prog->percentageComplete); // 1 completed out of 2 non-skipped
    }

    public function test_visit_step_has_zero_side_effects_on_business_facts(): void
    {
        $this->asPlatformAdmin();
        $serviceCode = 'zero_side_effects';
        $this->seedInsuranceService($serviceCode);

        $journey = new JourneyDefinition($serviceCode, 1, NavigationMode::FREE, [
            new StepDefinition('setup', StepType::FORM, 'Setup'),
            new StepDefinition('docs', StepType::DOCUMENTS, 'Docs'),
            new StepDefinition('quote', StepType::QUOTE, 'Quote'),
            new StepDefinition('payment', StepType::PAYMENT, 'Payment'),
        ]);
        $this->journeyRegistry->publish($journey, 'admin_1');

        $this->asAgency('agency_z');
        $this->seedRecipient('rec_z', 'agency_z');
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_z'), 'idem_z'));

        // Visit each step
        $this->journeyService->visit($req->id, 'setup');
        $this->journeyService->visit($req->id, 'docs');
        $this->journeyService->visit($req->id, 'quote');
        $this->journeyService->visit($req->id, 'payment');

        // Verify NO business side effects
        // 1. Workflow did not change state
        $wf = DB::table('workflow_instances')->where('resource_id', $req->id)->first();
        self::assertSame('draft', $wf->current_state);

        // 2. No quote was created or accepted
        self::assertFalse(DB::table('request_quotes')->where('request_id', $req->id)->exists());

        // 3. No billing intent was created
        self::assertFalse(DB::table('payment_intents')->where('resource_id', $req->id)->exists());

        // 4. No document files were uploaded
        self::assertFalse(DB::table('document_files')->join('document_sets', 'document_files.set_id', '=', 'document_sets.id')->where('document_sets.resource_id', $req->id)->exists());

        // 5. Only last_visited_step_key was recorded
        $row = DB::table('request_journeys')->where('request_id', $req->id)->first();
        self::assertSame('payment', $row->last_visited_step_key);
    }

    public function test_cross_agency_journey_access_is_denied(): void
    {
        $this->asPlatformAdmin();
        $serviceCode = 'iso_service';
        $this->seedService($serviceCode, 'wf_iso');

        $journey = new JourneyDefinition($serviceCode, 1, NavigationMode::LINEAR, [
            new StepDefinition('step1', StepType::FORM, 'Step 1'),
        ]);
        $this->journeyRegistry->publish($journey, 'admin_1');

        $this->asAgency('agency_owner');
        $this->seedRecipient('rec_iso', 'agency_owner');
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_iso'), 'idem_iso'));

        // Agency intruder attempts to view progress
        $this->asAgency('agency_intruder');
        $this->expectException(AuthorizationDenied::class);
        $this->journeyService->progress($req->id);
    }

    public function test_request_without_journey_definition_returns_null_safely(): void
    {
        $this->asPlatformAdmin();
        $serviceCode = 'legacy_service';
        $this->seedService($serviceCode, 'wf_legacy');

        $this->asAgency('agency_leg');
        $this->seedRecipient('rec_leg', 'agency_leg');
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_leg'), 'idem_leg'));

        // No journey definition was published for legacy_service
        $progress = $this->journeyService->progress($req->id);
        self::assertNull($progress);
    }

    public function test_locked_or_skipped_step_cannot_be_visited(): void
    {
        $this->asPlatformAdmin();
        $serviceCode = 'lock_test';
        $this->seedService($serviceCode, 'wf_lock', PricingMode::NoCharge);

        $journey = new JourneyDefinition($serviceCode, 1, NavigationMode::LINEAR, [
            new StepDefinition('step1', StepType::FORM, 'Step 1'),
            new StepDefinition('step_skipped', StepType::PAYMENT, 'Skipped Payment', skipWhenPricingModes: ['no_charge']),
            new StepDefinition('step_locked', StepType::CUSTOM, 'Locked Step', customFactKey: 'missing_fact', prerequisites: ['step_unmet']),
            new StepDefinition('step_unmet', StepType::CUSTOM, 'Unmet Step', customFactKey: 'unmet_fact'),
        ]);
        $this->journeyRegistry->publish($journey, 'admin_1');

        $this->asAgency('agency_lock');
        $this->seedRecipient('rec_lock', 'agency_lock');
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_lock'), 'idem_lock'));

        // 1. Attempting to visit locked step throws JourneyLockedStep
        try {
            $this->journeyService->visit($req->id, 'step_locked');
            self::fail('Expected JourneyLockedStep exception not thrown for locked step.');
        } catch (JourneyLockedStep $e) {
            self::assertStringContainsString('Cannot visit locked step', $e->getMessage());
        }

        // 2. Attempting to visit skipped step throws JourneyLockedStep
        try {
            $this->journeyService->visit($req->id, 'step_skipped');
            self::fail('Expected JourneyLockedStep exception not thrown for skipped step.');
        } catch (JourneyLockedStep $e) {
            self::assertStringContainsString('Cannot visit skipped step', $e->getMessage());
        }
    }

    public function test_completed_step_intentional_revisit_ux_behavior(): void
    {
        $this->asPlatformAdmin();

        $serviceCode = 'revisit_service';
        $this->seedNcrService($serviceCode);

        $journey = new JourneyDefinition($serviceCode, 1, NavigationMode::FREE, [
            new StepDefinition('personal', StepType::FORM, 'Personal Details', 'sec_personal'),
            new StepDefinition('family', StepType::FORM, 'Family Details', 'sec_family'),
        ]);
        $this->journeyRegistry->publish($journey, 'admin_1');

        $this->asAgency('agency_revisit');
        $this->seedRecipient('rec_revisit', 'agency_revisit');
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_revisit'), 'idem_revisit'));

        // 1. Agency completes personal details section (fulfills personal step)
        $this->forms->save($serviceCode, RequestResource::TYPE, $req->id, [
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'members' => [],
        ], 0);

        // 2. Journey progress without visit defaults to next available step ('family')
        $p1 = $this->journeyService->progress($req->id);
        self::assertSame('family', $p1->currentStepKey);
        self::assertSame(StepState::COMPLETED, $p1->step('personal')?->state);
        self::assertSame(StepState::CURRENT, $p1->step('family')?->state);

        // 3. User intentionally visits completed step 'personal' to review answers
        $this->journeyService->visit($req->id, 'personal');

        $p2 = $this->journeyService->progress($req->id);
        // currentStepKey reflects the explicitly visited completed step
        self::assertSame('personal', $p2->currentStepKey);
        // personal step remains COMPLETED (facts are not invalidated)
        self::assertSame(StepState::COMPLETED, $p2->step('personal')?->state);
        // family step remains AVAILABLE for completion
        self::assertSame(StepState::AVAILABLE, $p2->step('family')?->state);
        // Database is not mutated in any business tables
        self::assertSame('Alice', $this->forms->read($serviceCode, RequestResource::TYPE, $req->id)['answers']['first_name']);
    }

    private function requestPolicy(): array
    {
        return [
            'draft_state' => 'draft',
            'submit_actions' => ['submit', 'resubmit'],
            'submitted_states' => ['submitted', 'quoted', 'underwriting', 'active', 'correction_required'],
            'terminal_states' => ['approved', 'rejected', 'completed', 'cancelled'],
            'cancelled_state' => 'cancelled',
            'cancellable_states' => ['submitted'],
            'correction_states' => ['correction_required'],
            'correction_mode' => 'limited',
            'correction_action' => 'require_correction',
            'resubmit_action' => 'resubmit',
            'cancel_draft_action' => 'cancel_draft',
            'cancel_actions_by_state' => ['submitted' => 'cancel_submitted'],
            'recipient_field_map' => [],
        ];
    }

    private function seedService(string $serviceCode, string $workflowId, PricingMode $pricing = PricingMode::FixedCatalog): void
    {
        $wfCatalog = $this->app->make(DefinitionCatalog::class);
        $wf = WorkflowDefinition::fromArray([
            'id' => $workflowId,
            'version' => 1,
            'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'completed'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'complete', 'from' => 'submitted', 'to' => 'completed', 'allowed_roles' => ['government_admin']],
            ],
        ]);
        $wfCatalog->publish($wf);

        $catalog = $this->app->make(ServiceCatalog::class);
        $settings = [
            'request_management' => $this->requestPolicy(),
            'pricing_mode' => $pricing->value,
            'billing_currency' => 'USD',
            'refund_events' => [],
        ];
        if ($pricing === PricingMode::FixedCatalog) {
            $settings['billing_fixed_price_minor'] = 1000;
        }

        $draft = new ServiceDraft(
            code: $serviceCode,
            name: 'Test Service',
            description: 'Test Service Description',
            settings: $settings,
            workflow: new WorkflowBinding($workflowId, 1),
        );
        $svc = $catalog->create($draft);

        $formRegistry = $this->app->make(FormRegistry::class);
        $formDef = new FormDefinition($serviceCode, 1, [
            new FormField('field_1', 'Field 1', 'text', true),
        ]);
        $fRev = $formRegistry->saveDraft($formDef);
        $formRegistry->publishDraft($serviceCode, $fRev);

        $docRegistry = $this->app->make(DocumentRegistry::class);
        $docDef = new DocumentDefinition($serviceCode, 1, []);
        $dRev = $docRegistry->saveDraft($docDef);
        $docRegistry->publishDraft($serviceCode, $dRev);

        $catalog->activate($serviceCode, $svc->revision);
    }

    private function seedNcrService(string $serviceCode): void
    {
        $wfCatalog = $this->app->make(DefinitionCatalog::class);
        $wf = WorkflowDefinition::fromArray([
            'id' => 'wf_ncr',
            'version' => 1,
            'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'completed'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
            ],
        ]);
        $wfCatalog->publish($wf);

        $catalog = $this->app->make(ServiceCatalog::class);
        $draft = new ServiceDraft(
            code: $serviceCode,
            name: 'NCR Service',
            description: 'NCR Service Description',
            settings: [
                'request_management' => $this->requestPolicy(),
                'pricing_mode' => 'fixed_catalog',
                'billing_fixed_price_minor' => 5000,
                'billing_currency' => 'USD',
                'refund_events' => [],
            ],
            workflow: new WorkflowBinding('wf_ncr', 1),
        );
        $svc = $catalog->create($draft);

        $formRegistry = $this->app->make(FormRegistry::class);
        $formDef = new FormDefinition(
            serviceCode: $serviceCode,
            version: 1,
            fields: [
                new FormField('first_name', 'First Name', 'text', true),
                new FormField('last_name', 'Last Name', 'text', true),
            ],
            sections: [
                new FormSection('sec_personal', 'Personal Information', null, [
                    ['kind' => 'field', 'key' => 'first_name'],
                    ['kind' => 'field', 'key' => 'last_name'],
                ]),
                new FormSection('sec_family', 'Family Information', null, [
                    ['kind' => 'repeatable_group', 'key' => 'members'],
                ]),
            ],
            repeatableGroups: [
                new RepeatableGroupDefinition('members', 'Family Members', null, 0, 5, [
                    new FormField('name', 'Member Name', 'text', true),
                    new FormField('relation', 'Relation', 'text', true),
                ]),
            ],
        );
        $fRev = $formRegistry->saveDraft($formDef);
        $formRegistry->publishDraft($serviceCode, $fRev);

        $docRegistry = $this->app->make(DocumentRegistry::class);
        $docDef = new DocumentDefinition($serviceCode, 1, [
            new DocumentRequirement('national_id', 'National ID Card', true, ['application/pdf'], 2048),
        ]);
        $dRev = $docRegistry->saveDraft($docDef);
        $docRegistry->publishDraft($serviceCode, $dRev);

        $catalog->activate($serviceCode, $svc->revision);
    }

    private function seedInsuranceService(string $serviceCode): void
    {
        $wfCatalog = $this->app->make(DefinitionCatalog::class);
        $wf = WorkflowDefinition::fromArray([
            'id' => 'wf_ins',
            'version' => 1,
            'initial_state' => 'draft',
            'states' => ['draft', 'quoted', 'underwriting', 'active', 'completed'],
            'transitions' => [
                ['action' => 'propose_quote', 'from' => 'draft', 'to' => 'quoted', 'allowed_roles' => ['government_admin']],
                ['action' => 'accept_quote', 'from' => 'quoted', 'to' => 'underwriting', 'allowed_roles' => ['agency']],
                ['action' => 'activate', 'from' => 'underwriting', 'to' => 'active', 'allowed_roles' => ['government_admin']],
                ['action' => 'complete', 'from' => 'active', 'to' => 'completed', 'allowed_roles' => ['government_admin']],
            ],
        ]);
        $wfCatalog->publish($wf);

        $catalog = $this->app->make(ServiceCatalog::class);
        $draft = new ServiceDraft(
            code: $serviceCode,
            name: 'Insurance Policy Service',
            description: 'Insurance Policy Service Description',
            settings: [
                'request_management' => $this->requestPolicy(),
                'pricing_mode' => 'request_quote',
                'billing_currency' => 'USD',
                'refund_events' => [],
            ],
            workflow: new WorkflowBinding('wf_ins', 1),
        );
        $svc = $catalog->create($draft);

        $formRegistry = $this->app->make(FormRegistry::class);
        $formDef = new FormDefinition($serviceCode, 1, [
            new FormField('vehicle_model', 'Vehicle Model', 'text', true),
            new FormField('coverage_amount', 'Coverage Amount', 'number', true),
        ]);
        $fRev = $formRegistry->saveDraft($formDef);
        $formRegistry->publishDraft($serviceCode, $fRev);

        $docRegistry = $this->app->make(DocumentRegistry::class);
        $docDef = new DocumentDefinition($serviceCode, 1, [
            new DocumentRequirement('reg_doc', 'Vehicle Registration', true, ['application/pdf'], 2048),
            new DocumentRequirement('signed_proposal', 'Signed Proposal', false, ['application/pdf'], 2048),
        ]);
        $dRev = $docRegistry->saveDraft($docDef);
        $docRegistry->publishDraft($serviceCode, $dRev);

        $catalog->activate($serviceCode, $svc->revision);
    }

    private function seedRecipient(string $id, string $agencyId): void
    {
        DB::table('recipients')->insertOrIgnore([
            'id' => $id,
            'agency_id' => $agencyId,
            'kind' => 'person',
            'identity_type' => 'test_code',
            'identity_lookup' => hash('sha256', "{$agencyId}/citizen/{$id}"),
            'identity_cipher' => \Illuminate\Support\Facades\Crypt::encryptString("identity-{$id}"),
            'profile_cipher' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['first_name' => 'Test', 'last_name' => 'Citizen'], JSON_THROW_ON_ERROR)),
            'status' => 'active',
            'revision' => 1,
            'created_by' => 'fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asPlatformAdmin(): void
    {
        $user = JourneyTestAccount::firstOrCreate(
            ['email' => 'admin@intl.test'],
            ['name' => 'Platform Admin', 'password' => 'secret']
        );
        $user->syncRoles(['platform_admin']);
        Auth::guard('web')->setUser($user);
        $this->principals->override = new ActorContext((string) $user->id, 'platform_admin', null);
    }

    private function asGovernmentAdmin(string $orgId): void
    {
        $user = JourneyTestAccount::firstOrCreate(
            ['email' => 'gov@intl.test'],
            ['name' => 'Government Admin', 'password' => 'secret']
        );
        $user->syncRoles(['government_admin']);
        Auth::guard('web')->setUser($user);
        $this->principals->override = new ActorContext((string) $user->id, 'government_admin', $orgId);
    }

    private function asAgency(string $agencyId): void
    {
        $user = JourneyTestAccount::firstOrCreate(
            ['email' => "user@{$agencyId}.test"],
            ['name' => 'Agency User', 'password' => 'secret']
        );
        $user->syncRoles(['agency']);
        Auth::guard('web')->setUser($user);
        $this->principals->override = new ActorContext((string) $user->id, 'agency', $agencyId);
    }
}
