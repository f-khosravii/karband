<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intl\AuthorizationLaravel\Bridge\WorkflowActorResolver;
use Intl\AuthorizationLaravel\Bridge\WorkflowAuthorizationBridge;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Contracts\ScopeChecker;
use Intl\AuthorizationLaravel\Principal;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Documents\Definitions\DocumentDefinition;
use Intl\Documents\Definitions\DocumentRequirement;
use Intl\DocumentsLaravel\Contracts\FileSafetyScanner;
use Intl\DocumentsLaravel\Contracts\ReplacementPolicy;
use Intl\DocumentsLaravel\Contracts\ResourceVerifier;
use Intl\DocumentsLaravel\Exceptions\DocumentConflict;
use Intl\DocumentsLaravel\Exceptions\DocumentNotFound;
use Intl\DocumentsLaravel\Services\DocumentManager;
use Intl\DocumentsLaravel\Services\DocumentRegistry;
use Intl\DynamicForms\Definitions\FormDefinition;
use Intl\DynamicForms\Definitions\FormField;
use Intl\DynamicFormsLaravel\Services\FormRegistry;
use Intl\ServiceCatalog\Definitions\ServiceDraft;
use Intl\ServiceCatalog\Definitions\WorkflowBinding;
use Intl\ServiceCatalogLaravel\Services\ServiceCatalog;
use Intl\ServiceRequests\Policies\RequestPolicy;
use Intl\ServiceRequests\ValueObjects\RecipientReference;
use Intl\ServiceRequests\ValueObjects\RequestDraft;
use Intl\ServiceRequestsLaravel\Contracts\RecipientVerifier;
use Intl\ServiceRequestsLaravel\Contracts\RequestAudit;
use Intl\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier;
use Intl\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Intl\ServiceRequestsLaravel\Services\RequestLifecycle;
use Intl\ServiceRequestsLaravel\Services\ServiceRequests;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\WorkflowLaravel\Contracts\ActorResolver;
use Intl\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Intl\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class IntlDocumentsV2Test extends TestCase
{
    use RefreshDatabase;

    private DocV2Account $account;
    private DocV2Principal $principal;
    private DocV2Scope $scopes;
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('intl-documents.private_disk', 'local');
        config()->set('intl-documents.absolute_max_bytes', 10_485_760);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }
        foreach ([
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.deactivate', 'catalog.service.view', 'catalog.service.use',
            'workflow.definition.publish', 'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit',
            'workflow.action.require_correction', 'workflow.action.resubmit', 'workflow.action.move_to_underwriting', 'workflow.action.approve', 'workflow.action.cancel_draft',
            'forms.definition.edit', 'forms.definition.view', 'forms.definition.publish', 'forms.response.submit', 'forms.response.view',
            'documents.definition.edit', 'documents.definition.view', 'documents.definition.publish',
            'documents.file.upload', 'documents.file.view', 'documents.file.replace', 'documents.file.withdraw',
            'requests.create', 'requests.view', 'requests.correction.issue', 'requests.correction.resubmit',
            'requests.cancel.withdraw', 'requests.draft.delete', 'requests.representative.select',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.deactivate', 'catalog.service.view',
            'workflow.definition.publish', 'forms.definition.edit', 'forms.definition.view', 'forms.definition.publish',
            'documents.definition.edit', 'documents.definition.view', 'documents.definition.publish', 'requests.view',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'forms.definition.view', 'forms.response.view', 'requests.view', 'requests.correction.issue',
            'workflow.action.require_correction', 'workflow.action.move_to_underwriting', 'workflow.action.approve',
            'documents.definition.view', 'documents.file.view', 'documents.file.upload', 'documents.file.replace', 'documents.file.withdraw',
        ]);
        Role::findByName('agency', 'web')->givePermissionTo([
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit', 'workflow.action.resubmit', 'workflow.action.cancel_draft',
            'catalog.service.use', 'forms.response.submit', 'forms.response.view',
            'documents.file.upload', 'documents.file.view', 'documents.file.replace', 'documents.file.withdraw',
            'requests.create', 'requests.view', 'requests.correction.resubmit', 'requests.cancel.withdraw', 'requests.draft.delete',
            'requests.representative.select',
        ]);

        $this->account = DocV2Account::create([
            'name' => 'Documents V2 Tester', 'email' => 'docv2@example.test', 'password' => 'not-used',
        ]);
        $this->principal = new DocV2Principal();
        $this->scopes = new DocV2Scope();
        $this->app->instance(PrincipalProvider::class, $this->principal);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->app->instance(RecipientVerifier::class, new DocV2Recipients());
        $this->app->instance(ReplacementPolicy::class, new DocV2Replacement());
        $this->app->instance(FileSafetyScanner::class, new DocV2Scanner());
        $this->app->instance(RequestSubmissionVerifier::class, new DocV2Submission());
        $this->app->instance(\Intl\DocumentsLaravel\Contracts\DocumentStatePolicy::class, new DocV2DocumentStatePolicy());
        $this->app->instance(RequestAudit::class, new DocV2Audit());

        $this->seedRecipient('customer-1', 'person', 'agency-a');
        $this->seedRecipient('customer-2', 'person', 'agency-b');

        $this->asRole('platform_admin', 'platform');
        $this->setupInsuranceService();
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function seedRecipient(string $id, string $kind, string $agency): void
    {
        DB::table('intl_recipients')->insert([
            'id' => $id, 'agency_id' => $agency, 'kind' => $kind,
            'identity_type' => 'test_code',
            'identity_lookup' => hash('sha256', $agency . '/' . $kind . '/' . $id),
            'identity_cipher' => Crypt::encryptString('identity-' . $id),
            'profile_cipher' => Crypt::encryptString(json_encode(['full_name' => 'Alice', 'nickname' => 'Al'], JSON_THROW_ON_ERROR)),
            'status' => 'active', 'revision' => 1, 'created_by' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function asRole(string $role, ?string $organization): void
    {
        $this->account->syncRoles([$role]);
        Auth::guard('web')->setUser($this->account);
        $actor = new ActorContext((string) $this->account->getAuthIdentifier(), $role, $organization);
        $this->principal->principal = new Principal($this->account, $actor, 'web');
    }

    private function setupInsuranceService(): void
    {
        $this->app->make(DefinitionCatalog::class)->publish(WorkflowDefinition::fromArray([
            'id' => 'insurance.workflow', 'version' => 1, 'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'correction_required', 'underwriting', 'approved'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'require_correction', 'from' => 'submitted', 'to' => 'correction_required', 'allowed_roles' => ['government_admin']],
                ['action' => 'resubmit', 'from' => 'correction_required', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'move_to_underwriting', 'from' => 'submitted', 'to' => 'underwriting', 'allowed_roles' => ['government_admin']],
                ['action' => 'approve', 'from' => 'underwriting', 'to' => 'approved', 'allowed_roles' => ['government_admin']],
            ],
        ]));

        $policy = [
            'draft_state' => 'draft',
            'submit_actions' => ['submit', 'resubmit'],
            'submitted_states' => ['submitted', 'correction_required', 'underwriting', 'approved'],
            'terminal_states' => ['approved', 'rejected', 'cancelled'],
            'cancelled_state' => 'cancelled',
            'cancellable_states' => ['submitted', 'correction_required'],
            'correction_states' => ['correction_required'],
            'correction_mode' => 'limited',
            'correction_action' => 'require_correction',
            'resubmit_action' => 'resubmit',
            'cancel_draft_action' => 'cancel_draft',
            'cancel_actions_by_state' => ['submitted' => 'cancel_submitted', 'correction_required' => 'cancel_correction'],
            'recipient_field_map' => ['full_name' => 'insured_name'],
        ];

        $catalog = app(ServiceCatalog::class);
        $created = $catalog->create(new ServiceDraft('insurance', 'Insurance Service', null, ['request_management' => $policy], new WorkflowBinding('insurance.workflow', 1)));

        $formDef = new FormDefinition('insurance', 1, [
            new FormField('insured_name', 'Insured Name', 'text', true),
            new FormField('has_representative', 'Has Representative', 'checkbox', true),
        ]);
        $formReg = app(FormRegistry::class);
        $formRev = $formReg->saveDraft($formDef);
        $formReg->publishDraft('insurance', $formRev);

        $docDef = new DocumentDefinition('insurance', 1, [
            new DocumentRequirement('id_card', 'National ID Card', true, ['application/pdf'], 5_000_000),
            new DocumentRequirement('power_of_attorney', 'Power of Attorney', false, ['application/pdf'], 5_000_000, ['field' => 'has_representative', 'equals' => true]),
            new DocumentRequirement('proposal', 'Insurance Proposal', false, ['application/pdf'], 5_000_000),
            new DocumentRequirement('signed_proposal', 'Signed Proposal', false, ['application/pdf'], 5_000_000, null, ['underwriting', 'approved']),
            new DocumentRequirement('final_policy', 'Final Policy Certificate', false, ['application/pdf'], 5_000_000, null, ['approved']),
        ]);
        $docReg = app(DocumentRegistry::class);
        $docRev = $docReg->saveDraft($docDef);
        $docReg->publishDraft('insurance', $docRev);

        $catalog->activate('insurance', $created->revision);
    }

    private function registry(): ServiceRequests
    {
        return app(ServiceRequests::class);
    }

    private function docs(): DocumentManager
    {
        return app(DocumentManager::class);
    }

    private function pdf(string $name = 'doc.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'intl_doc_v2_');
        $this->temps[] = $path;
        file_put_contents($path, "%PDF-1.5\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }

    // 1. Insurance-derived capability scenario
    public function test_insurance_derived_document_capability_scenario(): void
    {
        // 1. Agency creates request in draft
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create(new RequestDraft('insurance', new RecipientReference('person', 'customer-1'), 'browser-ins-1'));
        $reqId = $req->id;

        // Save form answers
        $this->registry()->saveAnswers($reqId, ['insured_name' => 'Alice', 'has_representative' => false], 0);

        // Upload required id_card
        $up1 = $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'id_card', $this->pdf('id_card.pdf'), 0);
        self::assertSame(1, $up1['set_revision']);

        // Submit request
        $this->registry()->act($reqId, 'submit');

        // 2. Post-submission: Authorized Government admin generates/uploads proposal document
        $this->asRole('government_admin', 'government');
        $upProposal = $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'proposal', $this->pdf('official_proposal.pdf'), 1);
        self::assertSame('proposal', $upProposal['slot']);
        self::assertSame(2, $upProposal['set_revision']);

        // 3. Agency views/downloads the government-issued proposal
        $this->asRole('agency', 'agency-a');
        $stream = $this->docs()->open('insurance', 'intl.service_request', $reqId, $upProposal['id']);
        self::assertIsResource($stream);
        fclose($stream);

        // 4. Agency attempts to replace government proposal without permission -> DENIED
        try {
            $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'proposal', $this->pdf('fake_proposal.pdf'), 2);
            $this->fail('Agency should not be allowed to replace government proposal outside correction');
        } catch (RequestConflict|AuthorizationDenied) {
            self::assertTrue(true);
        }

        // 5. Agency attempts to upload unrelated submitted slot -> DENIED
        try {
            $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'id_card', $this->pdf('id_card2.pdf'), 2);
            $this->fail('Agency should not be allowed to modify submitted id_card outside correction');
        } catch (RequestConflict|\DomainException) {
            self::assertTrue(true);
        }

        // 6. Workflow advances to underwriting state
        $this->asRole('government_admin', 'government');
        $this->registry()->act($reqId, 'move_to_underwriting');

        // 7. Agency uploads signed_proposal in underwriting stage -> ALLOWED by state/slot policy
        $this->asRole('agency', 'agency-a');
        $upSigned = $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'signed_proposal', $this->pdf('signed_proposal.pdf'), 2);
        self::assertSame('signed_proposal', $upSigned['slot']);
        self::assertSame(3, $upSigned['set_revision']);

        // 8. Government admin approves request
        $this->asRole('government_admin', 'government');
        $this->registry()->act($reqId, 'approve');

        // 9. Government admin uploads final policy document
        $upPolicy = $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'final_policy', $this->pdf('final_policy.pdf'), 3);
        self::assertSame('final_policy', $upPolicy['slot']);
        self::assertSame(4, $upPolicy['set_revision']);

        // 10. Agency views final policy
        $this->asRole('agency', 'agency-a');
        $streamPolicy = $this->docs()->open('insurance', 'intl.service_request', $reqId, $upPolicy['id']);
        self::assertIsResource($streamPolicy);
        fclose($streamPolicy);
    }

    // 2. Cross-agency isolation
    public function test_cross_agency_document_isolation(): void
    {
        // Agency A creates request
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create(new RequestDraft('insurance', new RecipientReference('person', 'customer-1'), 'browser-a'));
        $this->docs()->upload('insurance', 'intl.service_request', $req->id, 'id_card', $this->pdf('id_a.pdf'), 0);
        $historyA = $this->docs()->history('insurance', 'intl.service_request', $req->id);
        $fileIdA = $historyA[0]['id'];

        // Agency B attempts to view or open Agency A document
        $this->asRole('agency', 'agency-b');
        $this->expectException(AuthorizationDenied::class);
        $this->docs()->open('insurance', 'intl.service_request', $req->id, $fileIdA);
    }

    // 3. Correction integration: unlocked document slot can be replaced/withdrawn; locked slot denied
    public function test_correction_round_unlocks_specific_document_slot(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create(new RequestDraft('insurance', new RecipientReference('person', 'customer-1'), 'browser-corr-1'));
        $reqId = $req->id;
        $this->registry()->saveAnswers($reqId, ['insured_name' => 'Alice', 'has_representative' => false], 0);
        $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'id_card', $this->pdf('id_old.pdf'), 0);
        $this->registry()->act($reqId, 'submit');

        // Government admin requires correction on document slot 'id_card'
        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($reqId, 'Please re-upload ID card', [], ['id_card']);

        // Agency replaces id_card -> SUCCEEDS
        $this->asRole('agency', 'agency-a');
        $replaced = $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'id_card', $this->pdf('id_new.pdf'), 1);
        self::assertSame(2, $replaced['slot_version']);

        // Agency attempts to upload into locked slot 'proposal' -> FAILS
        try {
            $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'proposal', $this->pdf('unlocked.pdf'), 2);
            $this->fail('Agency should not be allowed to upload into locked document slot during correction');
        } catch (\DomainException|RequestConflict) {
            self::assertTrue(true);
        }
    }

    // 4. Withdrawal in correction round
    public function test_document_withdrawal_in_correction_round(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create(new RequestDraft('insurance', new RecipientReference('person', 'customer-1'), 'browser-w-1'));
        $reqId = $req->id;
        $this->registry()->saveAnswers($reqId, ['insured_name' => 'Alice', 'has_representative' => false], 0);
        $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'id_card', $this->pdf('id_1.pdf'), 0);
        $this->registry()->act($reqId, 'submit');

        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($reqId, 'Fix ID', [], ['id_card']);

        // Agency withdraws active id_card
        $this->asRole('agency', 'agency-a');
        $withdrawn = $this->docs()->withdraw('insurance', 'intl.service_request', $reqId, 'id_card', 'Blurry image', 1);
        self::assertTrue($withdrawn['is_withdrawn']);

        $snap = $this->docs()->snapshot('insurance', 'intl.service_request', $reqId, ['has_representative' => false], 'correction_required');
        self::assertContains('id_card', $snap['missing']);
    }

    // 5. Platform admin explicit permission regression test
    public function test_platform_admin_cannot_upload_without_explicit_permission(): void
    {
        // 1. Agency creates and submits request
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create(new RequestDraft('insurance', new RecipientReference('person', 'customer-1'), 'browser-pa-1'));
        $reqId = $req->id;
        $this->registry()->saveAnswers($reqId, ['insured_name' => 'Alice', 'has_representative' => false], 0);
        $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'id_card', $this->pdf('id_card.pdf'), 0);
        $this->registry()->act($reqId, 'submit');

        // 2. Platform admin without documents.file.upload -> DENIED with AuthorizationDenied
        $this->asRole('platform_admin', 'platform');
        try {
            $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'proposal', $this->pdf('proposal.pdf'), 1);
            $this->fail('Platform admin without documents.file.upload must be denied');
        } catch (AuthorizationDenied) {
            self::assertTrue(true);
        }

        // 3. Platform admin with explicit documents.file.upload permission -> ALLOWED
        Role::findByName('platform_admin', 'web')->givePermissionTo('documents.file.upload');
        $this->account->givePermissionTo('documents.file.upload');
        $up = $this->docs()->upload('insurance', 'intl.service_request', $reqId, 'proposal', $this->pdf('proposal.pdf'), 1);
        self::assertSame('proposal', $up['slot']);
    }
}

final class DocV2Account extends BaseUser
{
    use HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class DocV2Principal implements PrincipalProvider
{
    public ?Principal $principal = null;
    public function current(): Principal { return $this->principal ?? throw new \RuntimeException('No authenticated user.'); }
}

final class DocV2Scope implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($request->resourceType === 'intl.service_request' && $actor->role === 'agency') {
            try {
                $row = DB::table('intl_service_requests')->where('id', $request->resourceId)->first();
                if ($row !== null && $actor->organization !== null) {
                    return $row->agency_id === $actor->organization;
                }
            } catch (\Throwable) {
                return true;
            }
        }
        return true;
    }
}

final class DocV2DocumentStatePolicy implements \Intl\DocumentsLaravel\Contracts\DocumentStatePolicy
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
        if ($serviceCode === 'insurance') {
            if ($currentState === 'submitted' && $slot === 'proposal' && in_array($actor->role, ['government_admin', 'platform_admin'], true)) {
                return true;
            }
            if ($currentState === 'underwriting' && $slot === 'signed_proposal' && $actor->role === 'agency') {
                return true;
            }
            if ($currentState === 'approved' && $slot === 'final_policy' && in_array($actor->role, ['government_admin', 'platform_admin'], true)) {
                return true;
            }
        }
        return false;
    }
}

final class DocV2Recipients implements RecipientVerifier
{
    public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool { return true; }
}

final class DocV2Replacement implements ReplacementPolicy
{
    public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool
    {
        return true;
    }
}

final class DocV2Scanner implements FileSafetyScanner
{
    public function assertSafe(string $temporaryPath, string $detectedMimeType): void {}
}

final class DocV2Submission implements RequestSubmissionVerifier
{
    public function assertSubmittable(array $submission): void {}
    public function assertReady(string $serviceCode, string $requestId): void {}
}

final class DocV2Audit implements RequestAudit
{
    public array $records = [];
    public function connectionName(): ?string { return DB::connection()->getName(); }
    public function record(string $event, string $requestId, array $safeMetadata = []): void
    {
        $this->records[] = ['event_code' => $event, 'resource_id' => $requestId, 'metadata' => $safeMetadata];
    }
}
