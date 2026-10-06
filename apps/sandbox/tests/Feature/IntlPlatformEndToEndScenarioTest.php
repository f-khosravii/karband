<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Contracts\ScopeChecker;
use Intl\AuthorizationLaravel\Principal;
use Intl\ServiceCatalog\Financial\PricingMode;
use Intl\Billing\ValueObjects\Money;
use Intl\BillingLaravel\Contracts\CentralPaymentGateway;
use Intl\BillingLaravel\Contracts\CentralRefundGateway;
use Intl\BillingLaravel\Services\PaymentService;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Documents\Definitions\DocumentDefinition;
use Intl\Documents\Definitions\DocumentRequirement;
use Intl\DocumentsLaravel\Services\DocumentManager;
use Intl\DocumentsLaravel\Services\DocumentRegistry;
use Intl\DynamicForms\Definitions\FormDefinition;
use Intl\DynamicForms\Definitions\FormField;
use Intl\DynamicForms\Definitions\FormSection;
use Intl\DynamicForms\Definitions\RepeatableGroupDefinition;
use Intl\DynamicFormsLaravel\Services\FormAnswers;
use Intl\DynamicFormsLaravel\Services\FormRegistry;
use Intl\ExternalAuth\Contracts\ExternalAuthProviderInterface;
use Intl\ExternalAuth\ValueObjects\ExternalIdentity;
use Intl\ExternalAuthLaravel\Contracts\ExternalPrincipalResolver;
use Intl\ExternalAuthLaravel\Services\ExternalAuthService;
use Intl\ExternalAuthLaravel\Testing\FakeEpishkhanV2Adapter;
use Intl\RequestJourney\Definitions\JourneyDefinition;
use Intl\RequestJourney\Definitions\NavigationMode;
use Intl\RequestJourney\Definitions\StepDefinition;
use Intl\RequestJourney\Definitions\StepState;
use Intl\RequestJourney\Definitions\StepType;
use Intl\RequestJourneyLaravel\Contracts\JourneyRegistryInterface;
use Intl\RequestJourneyLaravel\Contracts\JourneyServiceInterface;
use Intl\ServiceCatalog\Definitions\ServiceDraft;
use Intl\ServiceCatalog\Definitions\WorkflowBinding;
use Intl\ServiceCatalogLaravel\Services\ServiceCatalog;
use Intl\ServiceRequests\ValueObjects\RecipientReference;
use Intl\ServiceRequests\ValueObjects\RequestDraft;
use Intl\ServiceRequests\ValueObjects\RequestResource;
use Intl\ServiceRequestsLaravel\Services\RequestLifecycle;
use Intl\ServiceRequestsLaravel\Services\RequestQuoteService;
use Intl\ServiceRequestsLaravel\Services\ServiceRequests;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

class E2EUser extends BaseUser
{
    use HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class E2EPrincipalProvider implements PrincipalProvider
{
    public ?ActorContext $override = null;

    public function current(): Principal
    {
        $user = Auth::guard('web')->user() ?? new E2EUser([
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

final class E2EDocumentStatePolicy implements \Intl\DocumentsLaravel\Contracts\DocumentStatePolicy
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

final class E2EPaymentGateway implements CentralPaymentGateway
{
    public function preparePayment(\Intl\Billing\ValueObjects\PreparePaymentCommand $command): \Intl\Billing\ValueObjects\PreparePaymentOutcome
    {
        return \Intl\Billing\ValueObjects\PreparePaymentOutcome::prepared('e2e-prep-' . $command->paymentId, 0, 0, $command->amount->amount);
    }

    public function confirmPayment(\Intl\Billing\ValueObjects\ConfirmPaymentCommand $command): \Intl\Billing\ValueObjects\ConfirmPaymentOutcome
    {
        return \Intl\Billing\ValueObjects\ConfirmPaymentOutcome::succeeded($command->centralPaymentId, $command->amount->amount);
    }

    public function cancelPayment(\Intl\Billing\ValueObjects\CancelPaymentCommand $command): \Intl\Billing\ValueObjects\CancelPaymentOutcome
    {
        return \Intl\Billing\ValueObjects\CancelPaymentOutcome::cancelled($command->centralPaymentId);
    }

    public function lookupPayment(string $centralPaymentId): \Intl\Billing\ValueObjects\PaymentOutcome
    {
        return \Intl\Billing\ValueObjects\PaymentOutcome::succeeded($centralPaymentId);
    }

    public function lookupByOperationKey(string $operationKey): \Intl\Billing\ValueObjects\PaymentOutcome
    {
        return \Intl\Billing\ValueObjects\PaymentOutcome::succeeded('e2e-opkey');
    }

    public function charge(\Intl\Billing\ValueObjects\PaymentCommand $command): \Intl\Billing\ValueObjects\PaymentOutcome
    {
        return \Intl\Billing\ValueObjects\PaymentOutcome::succeeded('e2e-' . $command->paymentId);
    }

    public function lookup(\Intl\Billing\ValueObjects\PaymentCommand $command): \Intl\Billing\ValueObjects\PaymentOutcome
    {
        return \Intl\Billing\ValueObjects\PaymentOutcome::succeeded('e2e-' . $command->paymentId);
    }
}

final class E2ERefundGateway implements CentralRefundGateway
{
    public function createRefund(\Intl\Billing\Refund\RefundCommand $command): \Intl\Billing\Refund\RefundOutcome
    {
        return \Intl\Billing\Refund\RefundOutcome::succeeded('e2e-ref-' . $command->paymentId, $command->amount->amount);
    }

    public function lookupRefund(string $centralRefundId): \Intl\Billing\Refund\RefundOutcome
    {
        return \Intl\Billing\Refund\RefundOutcome::succeeded($centralRefundId);
    }

    public function lookupRefundByOperationKey(string $operationKey): \Intl\Billing\Refund\RefundOutcome
    {
        return \Intl\Billing\Refund\RefundOutcome::succeeded('e2e-ref-opkey');
    }

    public function refund(\Intl\Billing\Refund\RefundCommand $command): \Intl\Billing\Refund\RefundOutcome
    {
        return \Intl\Billing\Refund\RefundOutcome::succeeded('e2e-ref-' . $command->paymentId, $command->amount->amount);
    }

    public function lookup(\Intl\Billing\Refund\RefundCommand $command): \Intl\Billing\Refund\RefundOutcome
    {
        return \Intl\Billing\Refund\RefundOutcome::succeeded('e2e-ref-' . $command->paymentId);
    }
}

/**
 * INTL Platform Synthetic End-to-End Capability Scenario
 *
 * Exercises the cross-package orchestration:
 * External Auth -> Local Principal -> Request Draft -> Form Answers (Repeatable Group) ->
 * Documents -> Submit -> Correction -> Quote -> Post-Submit Document -> Payment ->
 * Final Document -> Journey Progress.
 */
final class IntlPlatformEndToEndScenarioTest extends TestCase
{
    use RefreshDatabase;

    private E2EPrincipalProvider $principals;
    private ServiceRequests $requestsService;
    private RequestLifecycle $lifecycle;
    private RequestQuoteService $quoteService;
    private PaymentService $paymentService;
    private FormAnswers $forms;
    private DocumentManager $documents;
    private JourneyServiceInterface $journeyService;
    private JourneyRegistryInterface $journeyRegistry;
    private FakeEpishkhanV2Adapter $authAdapter;
    private ExternalAuthService $externalAuth;

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
            'workflow.action.submit', 'workflow.action.resubmit', 'workflow.action.require_correction',
            'workflow.action.propose_quote', 'workflow.action.accept_quote', 'workflow.action.complete', 'workflow.action.activate',
            'forms.definition.edit', 'forms.definition.view', 'forms.definition.publish',
            'forms.response.submit', 'forms.response.view',
            'documents.definition.edit', 'documents.definition.view', 'documents.definition.publish',
            'documents.file.upload', 'documents.file.read', 'documents.file.download', 'documents.file.view', 'documents.set.reserve',
            'requests.create', 'requests.view', 'requests.submit', 'requests.cancel',
            'requests.correction.issue', 'requests.correction.resubmit',
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
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit', 'workflow.action.resubmit', 'workflow.action.accept_quote',
            'forms.response.submit', 'forms.response.view',
            'documents.file.upload', 'documents.file.read', 'documents.file.download', 'documents.file.view', 'documents.set.reserve',
            'requests.create', 'requests.view', 'requests.submit', 'requests.cancel', 'requests.correction.resubmit',
            'quote.accept', 'quote.reject', 'quote.view',
            'billing.payment.create', 'billing.payment.execute', 'billing.payment.view',
        ]);

        $this->principals = new E2EPrincipalProvider();
        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, new class implements ScopeChecker {
            public function allows(ActorContext $actor, AccessRequest $request): bool { return true; }
        });

        $this->app->instance(CentralPaymentGateway::class, new E2EPaymentGateway());
        $this->app->instance(CentralRefundGateway::class, new E2ERefundGateway());
        $this->app->instance(\Intl\DocumentsLaravel\Contracts\DocumentStatePolicy::class, new E2EDocumentStatePolicy());
        $this->app->instance(\Intl\DocumentsLaravel\Contracts\ReplacementPolicy::class, new class implements \Intl\DocumentsLaravel\Contracts\ReplacementPolicy {
            public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool { return true; }
        });
        $this->app->instance(\Intl\DocumentsLaravel\Contracts\FileSafetyScanner::class, new class implements \Intl\DocumentsLaravel\Contracts\FileSafetyScanner {
            public function assertSafe(string $temporaryPath, string $detectedMimeType): void {}
        });
        $this->app->instance(\Intl\ServiceRequestsLaravel\Contracts\RecipientVerifier::class, new class implements \Intl\ServiceRequestsLaravel\Contracts\RecipientVerifier {
            public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool { return true; }
        });
        $this->app->instance(\Intl\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier::class, new class implements \Intl\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier {
            public function assertReady(string $serviceCode, string $requestId): void {}
        });
        $this->app->instance(\Intl\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator::class, new class implements \Intl\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator {
            public function assertValid(string $serviceCode, array $profile): void {}
        });
        $this->app->instance(\Intl\BillingLaravel\Contracts\TrustedQuoteProvider::class, new class implements \Intl\BillingLaravel\Contracts\TrustedQuoteProvider {
            public function quote(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): Money {
                return new Money(500000, 'IRR');
            }
        });
        $this->app->instance(\Intl\BillingLaravel\Contracts\TrustedRefundPolicyProvider::class, new class implements \Intl\BillingLaravel\Contracts\TrustedRefundPolicyProvider {
            public function forPayment(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): \Intl\Billing\Refund\RefundPolicy {
                return \Intl\Billing\Refund\RefundPolicy::nonRefundable();
            }
        });
        $this->app->instance(\Intl\ServiceRequestsLaravel\Contracts\RequestAudit::class, new class implements \Intl\ServiceRequestsLaravel\Contracts\RequestAudit {
            public function record(string $event, string $requestId, array $context = []): void {}
            public function connectionName(): ?string { return DB::connection()->getName(); }
        });

        $this->authAdapter = new FakeEpishkhanV2Adapter();
        $this->app->instance(ExternalAuthProviderInterface::class, $this->authAdapter);
        $this->app->instance(ExternalPrincipalResolver::class, new class implements ExternalPrincipalResolver {
            public function resolve(ExternalIdentity $identity): Principal {
                $user = E2EUser::firstOrCreate(
                    ['email' => 'ext_' . $identity->subjectId . '@intl.test'],
                    ['name' => 'SSO ' . $identity->subjectId, 'password' => 'secret']
                );
                $user->syncRoles(['agency']);
                $actor = new ActorContext(
                    id: 'usr_' . $identity->subjectId,
                    role: 'agency',
                    organizationId: $identity->organizationId
                );
                return new Principal($user, $actor, 'web');
            }
        });

        config(['intl-billing.client_namespace' => 'e2e_test']);
        config(['intl-audit.allowed_events' => array_merge(config('intl-audit.allowed_events', []), [
            'request.quote.proposed', 'request.quote.accepted', 'request.quote.rejected',
            'requests.correction.requested', 'requests.correction.resubmitted',
        ])]);

        $this->app->bind(\Intl\WorkflowLaravel\Contracts\ActorResolver::class, \Intl\AuthorizationLaravel\Bridge\WorkflowActorResolver::class);
        $this->app->bind(\Intl\WorkflowLaravel\Contracts\WorkflowAuthorizer::class, \Intl\AuthorizationLaravel\Bridge\WorkflowAuthorizationBridge::class);

        $this->requestsService = $this->app->make(ServiceRequests::class);
        $this->lifecycle = $this->app->make(RequestLifecycle::class);
        $this->quoteService = $this->app->make(RequestQuoteService::class);
        $this->paymentService = $this->app->make(PaymentService::class);
        $this->forms = $this->app->make(FormAnswers::class);
        $this->documents = $this->app->make(DocumentManager::class);
        $this->journeyService = $this->app->make(JourneyServiceInterface::class);
        $this->journeyRegistry = $this->app->make(JourneyRegistryInterface::class);
        $this->externalAuth = $this->app->make(ExternalAuthService::class);
    }

    public function test_intl_platform_synthetic_end_to_end_capability_scenario(): void
    {
        $serviceCode = 'e2e_service';
        $agencyId = 'agency_e2e';
        $govOrgId = 'gov_dept_e2e';

        // -------------------------------------------------------------
        // Step 0: Platform Admin bootstraps Service Definitions & Journey
        // -------------------------------------------------------------
        $this->asPlatformAdmin();

        $wfCatalog = $this->app->make(DefinitionCatalog::class);
        $wfDef = WorkflowDefinition::fromArray([
            'id' => 'wf_e2e',
            'version' => 1,
            'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'correction_required', 'quoted', 'awaiting_payment', 'approved'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'require_correction', 'from' => 'submitted', 'to' => 'correction_required', 'allowed_roles' => ['government_admin'], 'comment_required' => true],
                ['action' => 'resubmit', 'from' => 'correction_required', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'propose_quote', 'from' => 'submitted', 'to' => 'quoted', 'allowed_roles' => ['government_admin']],
                ['action' => 'accept_quote', 'from' => 'quoted', 'to' => 'awaiting_payment', 'allowed_roles' => ['agency']],
                ['action' => 'complete', 'from' => 'awaiting_payment', 'to' => 'approved', 'allowed_roles' => ['government_admin']],
            ],
        ]);
        $wfRev = $wfCatalog->publish($wfDef);

        $catalog = $this->app->make(ServiceCatalog::class);
        $svc = $catalog->create(new ServiceDraft(
            $serviceCode,
            'Synthetic E2E Service',
            'End-to-End Orchestration Verification',
            [
                'pricing_mode' => PricingMode::RequestQuote->value,
                'refund_events' => [],
                'request_management' => $this->requestPolicy(),
            ],
            new WorkflowBinding('wf_e2e', $wfDef->version)
        ));

        // Forms Definition with Repeatable Group
        $formRegistry = $this->app->make(FormRegistry::class);
        $formDef = new FormDefinition(
            serviceCode: $serviceCode,
            version: 1,
            fields: [
                new FormField('full_name', 'Full Name', 'text', true),
                new FormField('email', 'Email Address', 'text', true),
            ],
            sections: [
                new FormSection('applicant', 'Applicant Details', null, [
                    ['kind' => 'field', 'key' => 'full_name'],
                    ['kind' => 'field', 'key' => 'email'],
                ]),
                new FormSection('dependents_sec', 'Dependents', null, [
                    ['kind' => 'repeatable_group', 'key' => 'dependents'],
                ]),
            ],
            repeatableGroups: [
                new RepeatableGroupDefinition('dependents', 'Dependents', null, 0, 10, [
                    new FormField('dep_name', 'Dependent Name', 'text', true),
                    new FormField('relation', 'Relationship', 'text', true),
                ]),
            ],
        );
        $fRev = $formRegistry->saveDraft($formDef);
        $formRegistry->publishDraft($serviceCode, $fRev);

        // Document Definition with regular and post-submit slots
        $docRegistry = $this->app->make(DocumentRegistry::class);
        $docDef = new DocumentDefinition($serviceCode, 1, [
            new DocumentRequirement('id_card', 'National ID Card', true, ['application/pdf'], 2048),
            new DocumentRequirement('signed_contract', 'Signed Contract', false, ['application/pdf'], 2048, requiredAt: ['awaiting_payment']),
            new DocumentRequirement('final_certificate', 'Final Certificate', false, ['application/pdf'], 2048, requiredAt: ['approved']),
        ]);
        $dRev = $docRegistry->saveDraft($docDef);
        $docRegistry->publishDraft($serviceCode, $dRev);

        $catalog->activate($serviceCode, $svc->revision);

        // Custom Fact Resolvers for signature and milestone certificate
        $factProvider = $this->app->make(\Intl\RequestJourneyLaravel\Services\DefaultJourneyFactProvider::class);
        $factProvider->registerCustomResolver('certificate_issued', function ($reqId, $svcCode, $app) {
            return \Illuminate\Support\Facades\DB::table('intl_document_files')
                ->join('intl_document_sets', 'intl_document_files.set_id', '=', 'intl_document_sets.id')
                ->where('intl_document_sets.resource_id', $reqId)
                ->where('intl_document_files.requirement_code', 'final_certificate')
                ->exists();
        });
        $factProvider->registerCustomResolver('signed_contract_present', function ($reqId, $svcCode, $app) {
            return \Illuminate\Support\Facades\DB::table('intl_document_files')
                ->join('intl_document_sets', 'intl_document_files.set_id', '=', 'intl_document_sets.id')
                ->where('intl_document_sets.resource_id', $reqId)
                ->where('intl_document_files.requirement_code', 'signed_contract')
                ->exists();
        });

        // Request Journey Definition (Linear mode)
        $journeyDef = new JourneyDefinition($serviceCode, 1, NavigationMode::LINEAR, [
            new StepDefinition('applicant_step', StepType::FORM, 'Applicant Details', 'applicant'),
            new StepDefinition('dependents_step', StepType::FORM, 'Dependents Group', 'dependents_sec'),
            new StepDefinition('id_card_step', StepType::DOCUMENTS, 'Upload ID Card'),
            new StepDefinition('quote_step', StepType::QUOTE, 'Quote Proposal & Acceptance'),
            new StepDefinition('contract_step', StepType::SIGNATURE, 'Signed Contract', customFactKey: 'signed_contract_present'),
            new StepDefinition('payment_step', StepType::PAYMENT, 'Fee Payment'),
            new StepDefinition('certificate_step', StepType::CUSTOM, 'Final Certificate', customFactKey: 'certificate_issued', availableWhenWorkflowStates: ['approved']),
        ]);
        $this->journeyRegistry->publish($journeyDef, 'admin_e2e');

        // -------------------------------------------------------------
        // Step 1: External Auth -> Local Principal
        // -------------------------------------------------------------
        $issued = new \DateTimeImmutable('now');
        $expires = new \DateTimeImmutable('+1 hour');
        $this->authAdapter->stageTicket(
            ticket: 'sso-ticket-e2e',
            subjectId: 'ext-usr-99',
            organizationId: $agencyId,
            sessionId: 'session-e2e',
            issuedAt: $issued,
            expiresAt: $expires,
            displayClaims: ['name' => 'Agency Representative', 'sso_claimed_role' => 'government_admin']
        );

        $ssoPrincipal = $this->externalAuth->authenticateTicket('sso-ticket-e2e');
        self::assertSame('agency', $ssoPrincipal->actor->role); // Local authorization is authoritative; claimed government_admin is rejected
        self::assertSame($agencyId, $ssoPrincipal->actor->organizationId);

        // Login as agency
        $this->asAgency($agencyId);
        $this->seedRecipient('rec_e2e_1', $agencyId);

        // -------------------------------------------------------------
        // Step 2: Create Request Draft & Initial Journey Verification
        // -------------------------------------------------------------
        $req = $this->requestsService->create(new RequestDraft($serviceCode, new RecipientReference('person', 'rec_e2e_1'), 'idem_e2e_1'));
        self::assertSame('draft', $req->currentState);

        $jProg1 = $this->journeyService->progress($req->id);
        self::assertSame(1, $jProg1->journeyVersion);
        self::assertSame(StepState::CURRENT, $jProg1->step('applicant_step')?->state);
        self::assertSame(StepState::LOCKED, $jProg1->step('dependents_step')?->state);

        // -------------------------------------------------------------
        // Step 3: Fill Dynamic Form Sections with Repeatable Group
        // -------------------------------------------------------------
        $this->forms->save($serviceCode, RequestResource::TYPE, $req->id, [
            'full_name' => 'John Doe',
            'email' => 'john@example.test',
            'dependents' => [
                ['_item_id' => 'dep_1', 'dep_name' => 'Junior Doe', 'relation' => 'son'],
            ],
        ], 0);

        // Journey advances: applicant & dependents completed; id_card is available / current
        $this->journeyService->visit($req->id, 'id_card_step');
        $jProg2 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $jProg2->step('applicant_step')?->state);
        self::assertSame(StepState::COMPLETED, $jProg2->step('dependents_step')?->state);
        self::assertSame(StepState::CURRENT, $jProg2->step('id_card_step')?->state);

        // -------------------------------------------------------------
        // Step 4: Upload ID Card Document & Submit Request
        // -------------------------------------------------------------
        $idPdf = $this->pdf('id_card.pdf');
        $this->documents->upload($serviceCode, RequestResource::TYPE, $req->id, 'id_card', $idPdf, 0);

        // Submit Draft
        $subRes = $this->requestsService->act($req->id, 'submit', null, 'act_submit_e2e');
        self::assertTrue($subRes->applied);
        self::assertSame('submitted', $this->requestsService->view($req->id)->currentState);

        // -------------------------------------------------------------
        // Step 5: Structured Correction Cycle
        // -------------------------------------------------------------
        $this->asGovernmentAdmin($govOrgId);
        $this->lifecycle->requireCorrection(
            $req->id,
            'Please add spouse in dependents and correct full name spelling.',
            ['full_name', 'dependents._add'],
            []
        );
        self::assertSame('correction_required', $this->requestsService->view($req->id)->currentState);

        // Agency applies structured correction: edits full_name and adds dependent
        $this->asAgency($agencyId);
        $this->forms->save($serviceCode, RequestResource::TYPE, $req->id, [
            'full_name' => 'Jonathan Doe',
            'email' => 'john@example.test',
            'dependents' => [
                ['_item_id' => 'dep_1', 'dep_name' => 'Junior Doe', 'relation' => 'son'],
                ['_item_id' => 'dep_2', 'dep_name' => 'Jane Doe', 'relation' => 'spouse'],
            ],
        ], 1);

        // Resubmit correction
        $resub = $this->lifecycle->resubmit($req->id);
        self::assertTrue($resub->applied);
        self::assertSame('submitted', $this->requestsService->view($req->id)->currentState);

        // -------------------------------------------------------------
        // Step 6: Request Quote (Propose & Accept)
        // -------------------------------------------------------------
        $this->asGovernmentAdmin($govOrgId);
        $this->requestsService->act($req->id, 'propose_quote', null, 'act_propose_quote_e2e');
        $proposal = $this->quoteService->propose($req->id, 500000, 'IRR', 'Standard Assessment Fee');
        self::assertSame('quoted', $this->requestsService->view($req->id)->currentState);

        // Agency visits and accepts quote
        $this->asAgency($agencyId);
        $this->journeyService->visit($req->id, 'quote_step');
        $this->quoteService->accept($req->id, $proposal->revision);
        $this->requestsService->act($req->id, 'accept_quote', null, 'act_accept_quote_e2e');
        self::assertSame('awaiting_payment', $this->requestsService->view($req->id)->currentState);

        // -------------------------------------------------------------
        // Step 7: Post-Submit Signed Contract Document Upload
        // -------------------------------------------------------------
        $contractPdf = $this->pdf('contract.pdf');
        $this->documents->upload($serviceCode, RequestResource::TYPE, $req->id, 'signed_contract', $contractPdf, 1);
        $this->journeyService->visit($req->id, 'payment_step');

        // -------------------------------------------------------------
        // Step 8: Billing V2 Two-Stage Payment Execution
        // -------------------------------------------------------------
        $intent = $this->paymentService->create($serviceCode, RequestResource::TYPE, $req->id, 'idem_pay_e2e');
        $this->paymentService->preparePayment($intent->id, 'op_prep_e2e');
        $this->paymentService->confirmPayment($intent->id, 'op_conf_e2e', 'c_hold_' . $intent->id);

        $jProg3 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::COMPLETED, $jProg3->step('payment_step')?->state);
        self::assertSame(StepState::LOCKED, $jProg3->step('certificate_step')?->state); // Locked until workflow moves to 'approved'

        // -------------------------------------------------------------
        // Step 9: Workflow Transition to Approved & Final Certificate Upload
        // -------------------------------------------------------------
        $this->asGovernmentAdmin($govOrgId);
        $compRes = $this->requestsService->act($req->id, 'complete', null, 'act_complete_e2e');
        self::assertTrue($compRes->applied);
        self::assertSame('approved', $this->requestsService->view($req->id)->currentState);

        // Journey reflects certificate_step unlocked and AVAILABLE
        $jProg4 = $this->journeyService->progress($req->id);
        self::assertSame(StepState::AVAILABLE, $jProg4->step('certificate_step')?->state);

        // Upload final certificate
        $certPdf = $this->pdf('cert.pdf');
        $this->documents->upload($serviceCode, RequestResource::TYPE, $req->id, 'final_certificate', $certPdf, 2);

        // -------------------------------------------------------------
        // Step 10: Final Journey Progress Verification (100% Completed)
        // -------------------------------------------------------------
        $this->asAgency($agencyId);
        $this->journeyService->visit($req->id, 'certificate_step');
        $finalProg = $this->journeyService->progress($req->id);

        self::assertSame(100.0, $finalProg->percentageComplete);
        self::assertSame('certificate_step', $finalProg->currentStepKey);
        self::assertSame(StepState::COMPLETED, $finalProg->step('applicant_step')?->state);
        self::assertSame(StepState::COMPLETED, $finalProg->step('dependents_step')?->state);
        self::assertSame(StepState::COMPLETED, $finalProg->step('id_card_step')?->state);
        self::assertSame(StepState::COMPLETED, $finalProg->step('quote_step')?->state);
        self::assertSame(StepState::COMPLETED, $finalProg->step('contract_step')?->state);
        self::assertSame(StepState::COMPLETED, $finalProg->step('payment_step')?->state);
        self::assertSame(StepState::COMPLETED, $finalProg->step('certificate_step')?->state);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf_e2e_');
        file_put_contents($path, "%PDF-1.5\n%Header\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function seedRecipient(string $id, string $agencyId): void
    {
        DB::table('intl_recipients')->insertOrIgnore([
            'id' => $id,
            'agency_id' => $agencyId,
            'kind' => 'person',
            'identity_type' => 'test_code',
            'identity_lookup' => hash('sha256', "{$agencyId}/citizen/{$id}"),
            'identity_cipher' => \Illuminate\Support\Facades\Crypt::encryptString("identity-{$id}"),
            'profile_cipher' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['full_name' => 'E2E Citizen'], JSON_THROW_ON_ERROR)),
            'status' => 'active',
            'revision' => 1,
            'created_by' => 'fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asPlatformAdmin(): void
    {
        $user = E2EUser::firstOrCreate(
            ['email' => 'admin_e2e@intl.test'],
            ['name' => 'Platform Admin', 'password' => 'secret']
        );
        $user->syncRoles(['platform_admin']);
        Auth::guard('web')->setUser($user);
        $this->principals->override = new ActorContext((string) $user->id, 'platform_admin', null);
    }

    private function asGovernmentAdmin(string $orgId): void
    {
        $user = E2EUser::firstOrCreate(
            ['email' => 'gov_e2e@intl.test'],
            ['name' => 'Government Admin', 'password' => 'secret']
        );
        $user->syncRoles(['government_admin']);
        Auth::guard('web')->setUser($user);
        $this->principals->override = new ActorContext((string) $user->id, 'government_admin', $orgId);
    }

    private function asAgency(string $agencyId): void
    {
        $user = E2EUser::firstOrCreate(
            ['email' => "user_{$agencyId}@intl.test"],
            ['name' => 'Agency User', 'password' => 'secret']
        );
        $user->syncRoles(['agency']);
        Auth::guard('web')->setUser($user);
        $this->principals->override = new ActorContext((string) $user->id, 'agency', $agencyId);
    }

    private function requestPolicy(): array
    {
        return [
            'draft_state' => 'draft',
            'submit_actions' => ['submit', 'resubmit'],
            'submitted_states' => ['submitted', 'quoted', 'awaiting_payment', 'correction_required'],
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
}
