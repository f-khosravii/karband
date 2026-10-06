<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Bridges\WorkflowActorResolver;
use App\Bridges\WorkflowAuthorizationBridge;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Documents\Definitions\DocumentDefinition;
use Karband\DocumentsLaravel\Contracts\FileSafetyScanner;
use Karband\DocumentsLaravel\Contracts\ReplacementPolicy;
use Karband\DocumentsLaravel\Services\DocumentRegistry;
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
use Karband\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator;
use Karband\ServiceRequestsLaravel\Services\RequestLifecycle;
use App\Bridges\LocalRecipientRequestBridge;
use App\Bridges\TrustedCancellationRefundVerifier;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Karband\ServiceRequestsLaravel\Services\ServiceRequests;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Karband\WorkflowLaravel\Services\WorkflowRuntime;
use Karband\DynamicFormsLaravel\Services\FormAnswers;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

/** Requires all previously generated patches, including Documents and Billing, in LOCAL Sandbox. */
final class ServiceRequestsCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private ServiceRequestsTestAccount $account;
    private ServiceRequestsTestPrincipal $principals;
    private ServiceRequestsTestRecipients $recipients;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $role) { Role::findOrCreate($role, 'web'); }
        foreach ([
            'workflow.definition.publish','workflow.instance.start','workflow.instance.view','workflow.action.submit',
            'catalog.service.create','catalog.service.activate','catalog.service.deactivate','catalog.service.view','catalog.service.use',
            'forms.definition.edit','forms.definition.publish','forms.response.submit','forms.response.view',
            'documents.definition.edit','documents.definition.publish','documents.file.upload','documents.file.view',
            'requests.create','requests.view','requests.restore','requests.deleted.view',
            'requests.cancel.draft','requests.cancel.request','requests.cancel.decide','requests.cancel.withdraw',
            'requests.correction.issue','requests.correction.resubmit','requests.draft.delete',
            'requests.representative.select',
            'workflow.action.require_correction','workflow.action.resubmit','workflow.action.cancel_draft',
            'workflow.action.cancel_submitted','workflow.action.cancel_correction','workflow.action.approve',
        ] as $permission) { Permission::findOrCreate($permission, 'web'); }
        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'workflow.definition.publish','catalog.service.create','catalog.service.activate','catalog.service.deactivate','catalog.service.view',
            'forms.definition.edit','forms.definition.publish','documents.definition.edit','documents.definition.publish',
            'requests.view','requests.restore','requests.deleted.view',
        ]);
        Role::findByName('agency', 'web')->givePermissionTo([
            'workflow.instance.start','workflow.instance.view','workflow.action.submit','catalog.service.use',
            'forms.response.submit','forms.response.view','documents.file.upload','documents.file.view',
            'requests.create','requests.view','requests.cancel.draft','requests.cancel.request',
            'requests.cancel.withdraw','requests.correction.resubmit','requests.draft.delete',
            'requests.representative.select','workflow.action.resubmit','workflow.action.cancel_draft',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'requests.view','requests.cancel.decide','requests.correction.issue',
            'workflow.action.require_correction','workflow.action.cancel_submitted',
            'workflow.action.cancel_correction','workflow.action.approve',
        ]);
        $this->account = ServiceRequestsTestAccount::create([
            'name'=>'Request test','email'=>'request-test@example.test','password'=>'unused',
        ]);
        $this->principals = new ServiceRequestsTestPrincipal();
        $this->recipients = new ServiceRequestsTestRecipients();
        $this->app->instance(PrincipalProvider::class, $this->principals);
        // Deliberately permissive scope to test the request package's defensive agency ownership check.
        $this->app->instance(ScopeChecker::class, new ServiceRequestsTestScope());
        $this->app->instance(RecipientVerifier::class, $this->recipients);
        $this->app->instance(ReplacementPolicy::class, new ServiceRequestsTestReplacement());
        $this->app->instance(FileSafetyScanner::class, new ServiceRequestsTestScanner());
        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->app->instance(RequestSubmissionVerifier::class, new ServiceRequestsTestSubmission());
        $this->app->instance(RepresentativeProfileValidator::class, new ServiceRequestsTestRepProfile());
        $this->app->instance(RequestAudit::class, new ServiceRequestsTestAudit());
        config(['service-requests.legal_representative_mode'=>'request']);
        $this->seedRecipient('customer-1','person','agency-a');
        $this->seedRecipient('customer-2','person','agency-a');
        $this->seedRecipient('company-1','legal','agency-a');
        $this->asRole('platform_admin', 'platform');
        $this->publishService();
        $this->asRole('agency', 'agency-a');
    }

    private function seedRecipient(string $id, string $kind, string $agency): void
    {
        \DB::table('recipients')->insert([
            'id'=>$id, 'agency_id'=>$agency,'kind'=>$kind,
            'identity_type'=>'test_code',
            'identity_lookup'=>hash('sha256',$agency.'/'.$kind.'/'.$id),
            'identity_cipher'=>Crypt::encryptString('identity-'.$id),
            'profile_cipher'=>Crypt::encryptString(json_encode(['full_name'=>'Alice','nickname'=>'Al'],JSON_THROW_ON_ERROR)),
            'status'=>'active','revision'=>1,'created_by'=>'fixture','created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private static function requestPolicy(): array
    {
        return [
            'draft_state'=>'draft','submit_actions'=>['submit','resubmit'],
            'submitted_states'=>['submitted','correction_required'],
            'terminal_states'=>['approved','rejected','cancelled'], 'cancelled_state'=>'cancelled',
            'cancellable_states'=>['submitted','correction_required'],
            'correction_states'=>['correction_required'],'correction_mode'=>'limited',
            'correction_action'=>'require_correction','resubmit_action'=>'resubmit',
            'cancel_draft_action'=>'cancel_draft',
            'cancel_actions_by_state'=>['submitted'=>'cancel_submitted','correction_required'=>'cancel_correction'],
            'recipient_field_map'=>['full_name'=>'name'],
        ];
    }

    private function asRole(string $role, ?string $org): void
    {
        $this->account->syncRoles([$role]);
        Auth::guard('web')->setUser($this->account);
        $this->principals->principal = new Principal($this->account,
            new ActorContext((string) $this->account->getAuthIdentifier(), $role, $org), 'web');
    }

    private function publishService(bool $publishForm = true): void
    {
        app(DefinitionCatalog::class)->publish(WorkflowDefinition::fromArray([
            'id'=>'benefit.workflow','version'=>1,'initial_state'=>'draft',
            'states'=>['draft','submitted','correction_required','approved','rejected','cancelled'],
            'transitions'=>[
                ['action'=>'submit','from'=>'draft','to'=>'submitted','allowed_roles'=>['agency']],
                ['action'=>'require_correction','from'=>'submitted','to'=>'correction_required',
                    'allowed_roles'=>['government_admin'],'comment_required'=>true],
                ['action'=>'resubmit','from'=>'correction_required','to'=>'submitted','allowed_roles'=>['agency']],
                ['action'=>'approve','from'=>'submitted','to'=>'approved','allowed_roles'=>['government_admin']],
                ['action'=>'cancel_draft','from'=>'draft','to'=>'cancelled','allowed_roles'=>['agency']],
                ['action'=>'cancel_submitted','from'=>'submitted','to'=>'cancelled','allowed_roles'=>['government_admin']],
                ['action'=>'cancel_correction','from'=>'correction_required','to'=>'cancelled',
                    'allowed_roles'=>['government_admin']],
            ],
        ]));
        $catalog = app(ServiceCatalog::class);
        $service = $catalog->create(new ServiceDraft('benefit', 'Benefit', null, ['request_management'=>self::requestPolicy()], new WorkflowBinding('benefit.workflow', 1)));
        if ($publishForm) {
            $forms = app(FormRegistry::class);
            $revision = $forms->saveDraft(new FormDefinition('benefit', 1, [new FormField('name','Name','text',true), new FormField('note','Note','text',false)]));
            $forms->publishDraft('benefit', $revision);
        }
        $documents = app(DocumentRegistry::class);
        $revision = $documents->saveDraft(new DocumentDefinition('benefit', 1, []));
        $documents->publishDraft('benefit', $revision);
        $catalog->activate('benefit', $service->revision);
    }

    private function registry(): ServiceRequests { return app(ServiceRequests::class); }

    private function draft(string $recipient = 'customer-1', string $key = 'browser-1'): RequestDraft
    {
        return new RequestDraft('benefit', new RecipientReference('person', $recipient), $key);
    }

    public function test_creation_pins_workflow_form_and_document_without_a_second_status(): void
    {
        $request = $this->registry()->create($this->draft());
        self::assertSame('draft', $request->currentState);
        self::assertSame(1, $request->workflowVersion);
        self::assertSame(1, $request->formVersion);
        self::assertSame(1, $request->documentVersion);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('workflow_instances', 1);
        $this->assertDatabaseCount('form_answers', 1);
        $this->assertDatabaseCount('document_sets', 1);
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('service_requests', 'status'));
        self::assertSame(RequestResource::TYPE,
            \DB::table('workflow_instances')->where('id', $request->workflowInstanceId)->value('resource_type'));
    }

    public function test_idempotent_replay_returns_the_same_request(): void
    {
        $first = $this->registry()->create($this->draft());
        $again = $this->registry()->create($this->draft());
        self::assertSame($first->id, $again->id);
        $this->assertDatabaseCount('workflow_instances', 1);
    }

    public function test_reusing_key_for_different_recipient_is_denied(): void
    {
        $this->registry()->create($this->draft());
        $this->expectException(RequestConflict::class);
        $this->registry()->create($this->draft('customer-2'));
    }

    public function test_unverified_business_recipient_cannot_create_request(): void
    {
        $this->recipients->allow = false;
        try {
            $this->registry()->create($this->draft());
            $this->fail('Recipient verification must be mandatory.');
        } catch (AuthorizationDenied) {
            $this->assertDatabaseCount('service_requests', 0);
            $this->assertDatabaseCount('workflow_instances', 0);
        }
    }

    public function test_verifier_exception_fails_closed(): void
    {
        $this->recipients->throw = true;
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->create($this->draft());
    }

    public function test_government_cannot_create_even_with_view_permission(): void
    {
        $this->asRole('government_admin', 'local-government');
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->create($this->draft());
    }

    public function test_agency_cannot_view_a_request_from_another_agency_even_with_bad_scope(): void
    {
        $id = $this->registry()->create($this->draft())->id;
        $this->asRole('agency', 'agency-b');
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->view($id);
    }

    public function test_government_with_explicit_view_permission_can_read_local_service_request(): void
    {
        $id = $this->registry()->create($this->draft())->id;
        $this->asRole('government_admin', 'local-government');
        self::assertSame($id, $this->registry()->view($id)->id);
    }

    public function test_state_is_always_read_from_workflow(): void
    {
        $id = $this->registry()->create($this->draft())->id;
        $this->registry()->saveAnswers($id,['name'=>'Alice','note'=>'keep'],0);
        $result = $this->registry()->act($id, 'submit', null, 'submit-1');
        self::assertTrue($result->applied);
        self::assertSame('submitted', $this->registry()->view($id)->currentState);
        $this->assertDatabaseCount('workflow_history', 1);
    }

    public function test_existing_creation_replays_after_service_deactivation_but_new_one_is_denied(): void
    {
        $original = $this->registry()->create($this->draft());
        $this->asRole('platform_admin', 'platform');
        $catalog = app(ServiceCatalog::class);
        $current = $catalog->get('benefit');
        $catalog->deactivate('benefit', $current->revision);
        $this->asRole('agency', 'agency-a');
        self::assertSame($original->id, $this->registry()->create($this->draft())->id);
        try {
            $this->registry()->create($this->draft('customer-2','browser-2'));
            $this->fail('Inactive service must not accept new requests.');
        } catch (RequestConflict) {
            $this->assertDatabaseCount('service_requests', 1);
        }
    }

    public function test_unpublished_form_rolls_back_entire_local_creation(): void
    {
        // Delete published form before agency starts. No request, doc set or Workflow may persist.
        \DB::table('form_definitions')->where('service_code','benefit')->delete();
        try {
            $this->registry()->create($this->draft());
            $this->fail('Creation must reject missing published form.');
        } catch (\Karband\DynamicFormsLaravel\Exceptions\FormNotFound) {
            $this->assertDatabaseCount('service_requests', 0);
            $this->assertDatabaseCount('document_sets', 0);
            $this->assertDatabaseCount('workflow_instances', 0);
        }
    }

    public function test_creating_a_request_does_not_create_or_dispatch_payment(): void
    {
        $this->registry()->create($this->draft());
        $this->assertDatabaseCount('payment_intents', 0);
    }

    public function test_draft_soft_delete_hides_from_agency_and_platform_can_restore(): void
    {
        $id = $this->registry()->create($this->draft())->id;
        app(RequestLifecycle::class)->deleteDraft($id);
        try { $this->registry()->view($id); $this->fail('Deleted drafts must be invisible to agency.'); }
        catch (\Karband\ServiceRequestsLaravel\Exceptions\RequestNotFound) {}
        $this->asRole('platform_admin','platform');
        app(RequestLifecycle::class)->restoreDraft($id);
        $this->asRole('agency','agency-a');
        self::assertSame('draft',$this->registry()->view($id)->currentState);
    }

    public function test_agency_cancellation_is_pending_and_denial_retains_prior_state(): void
    {
        $id = $this->registry()->create($this->draft())->id;
        $this->registry()->saveAnswers($id,['name'=>'Alice','note'=>'keep'],0);
        $this->registry()->act($id,'submit');
        $cancellationId = app(RequestLifecycle::class)->requestCancellation($id);
        self::assertTrue($this->registry()->view($id)->pendingCancellation);
        $this->asRole('government_admin','government');
        try { $this->registry()->act($id,'approve'); $this->fail('Approval during cancellation must fail.'); }
        catch (RequestConflict) {}
        app(RequestLifecycle::class)->decideCancellation($id,false); // reason optional
        self::assertSame('submitted',$this->registry()->view($id)->currentState);
        self::assertFalse($this->registry()->view($id)->pendingCancellation);
        self::assertSame('denied',\DB::table('request_cancellations')->where('id',$cancellationId)->value('status'));
    }

    public function test_agency_can_withdraw_pending_cancellation(): void
    {
        $id=$this->registry()->create($this->draft())->id;
        $this->registry()->saveAnswers($id,['name'=>'Alice'],0);
        $this->registry()->act($id,'submit');
        app(RequestLifecycle::class)->requestCancellation($id);
        app(RequestLifecycle::class)->withdrawCancellation($id);
        self::assertFalse($this->registry()->view($id)->pendingCancellation);
        self::assertSame('submitted',$this->registry()->view($id)->currentState);
    }

    public function test_limited_correction_blocks_unlisted_form_fields_and_resubmits(): void
    {
        $id=$this->registry()->create($this->draft())->id;
        $this->registry()->saveAnswers($id,['name'=>'Alice','note'=>'keep'],0);
        $this->registry()->act($id,'submit');
        $this->asRole('government_admin','government');
        app(RequestLifecycle::class)->requireCorrection($id,'Name requires correction',['name']);
        $this->asRole('agency','agency-a');
        try { $this->registry()->saveAnswers($id,['name'=>'Bob','note'=>'changed'],1);
            $this->fail('Non-allowlisted field must remain locked.'); }
        catch (\DomainException) {}
        $this->registry()->saveAnswers($id,['name'=>'Bob','note'=>'keep'],1);
        app(RequestLifecycle::class)->resubmit($id);
        self::assertSame('submitted',$this->registry()->view($id)->currentState);
        self::assertSame(0,\DB::table('request_corrections')->where('request_id',$id)->where('active',true)->count());
    }

    public function test_approved_cancellation_is_a_trusted_refund_event_without_triggering_remote_payment(): void
    {
        $id=$this->registry()->create($this->draft())->id;
        $this->registry()->saveAnswers($id,['name'=>'Alice'],0);
        $this->registry()->act($id,'submit');
        $eventId=app(RequestLifecycle::class)->requestCancellation($id);
        $this->asRole('government_admin','government');
        $result=app(RequestLifecycle::class)->decideCancellation($id,true);
        self::assertTrue($result?->applied ?? false);
        self::assertSame('cancelled',$this->registry()->view($id)->currentState);
        self::assertTrue(app(TrustedCancellationRefundVerifier::class)->verify(
            'cancellation_approved',$eventId,'benefit',RequestResource::TYPE,$id,'agency-a'));
        self::assertFalse(app(TrustedCancellationRefundVerifier::class)->verify(
            'cancellation_approved','wrong','benefit',RequestResource::TYPE,$id,'agency-a'));
        $this->assertDatabaseCount('payment_intents',0);
    }

    public function test_profile_sync_changes_only_unsubmitted_drafts_and_freeze_is_permanent(): void
    {
        $id=$this->registry()->create($this->draft())->id;
        app(LocalRecipientRequestBridge::class)->syncEditableDrafts('agency-a','customer-1',
            ['full_name'=>'Updated','nickname'=>'New'],'test_code','identity-customer-1');
        self::assertSame('Updated',$this->registry()->readAnswers($id)['answers']['name']);
        app(LocalRecipientRequestBridge::class)->freezeDrafts('agency-a','customer-1');
        try { $this->registry()->saveAnswers($id,['name'=>'attempt'],1);
            $this->fail('Archived-recipient drafts must remain frozen.'); }
        catch (RequestConflict) {}
    }

    public function test_direct_workflow_runtime_cannot_bypass_service_requests(): void
    {
        $request = $this->registry()->create($this->draft());
        $this->expectException(RequestConflict::class);
        app(WorkflowRuntime::class)->apply($request->workflowInstanceId, 'submit');
    }

    public function test_direct_form_service_cannot_edit_while_cancellation_is_pending(): void
    {
        $request = $this->registry()->create($this->draft());
        $this->registry()->saveAnswers($request->id, ['name'=>'Alice'], 0);
        $this->registry()->act($request->id, 'submit');
        app(RequestLifecycle::class)->requestCancellation($request->id);
        $this->expectException(RequestConflict::class);
        app(FormAnswers::class)->save('benefit', RequestResource::TYPE, $request->id, ['name'=>'Unauthorized'], 1);
    }

    public function test_one_legal_representative_snapshot_per_request(): void
    {
        $id=$this->registry()->create(new RequestDraft('benefit',new RecipientReference('legal','company-1'),'legal-1'))->id;
        app(RequestLifecycle::class)->selectLegalRepresentative($id,null,['name'=>'Designated representative'],0);
        self::assertSame(1,\DB::table('request_representatives')->where('request_id',$id)->count());
        $this->expectException(RequestConflict::class);
        app(RequestLifecycle::class)->selectLegalRepresentative($id,null,['name'=>'Second'],0);
    }
}

final class ServiceRequestsTestAccount extends BaseUser
{
    use HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class ServiceRequestsTestPrincipal implements PrincipalProvider
{
    public ?Principal $principal = null;
    public function current(): Principal { return $this->principal ?? throw new \RuntimeException('No authenticated user.'); }
}

final class ServiceRequestsTestScope implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool { return true; }
}

final class ServiceRequestsTestRecipients implements RecipientVerifier
{
    public bool $allow = true;
    public bool $throw = false;
    public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool
    {
        if ($this->throw) { throw new \RuntimeException('Recipient directory unavailable'); }
        return $this->allow && $serviceCode === 'benefit' && $agencyId === 'agency-a' && in_array($recipient->type,['person','legal'],true);
    }
}

final class ServiceRequestsTestReplacement implements ReplacementPolicy
{
    public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool { return false; }
}

/** Test-only no-op: REAL deployment MUST bind a real scanner before any file upload. */
final class ServiceRequestsTestScanner implements FileSafetyScanner
{
    public function assertSafe(string $temporaryPath, string $detectedMimeType): void {}
}


final class ServiceRequestsTestSubmission implements RequestSubmissionVerifier
{
    // Fixture only. Production MUST check representative authority and all service-specific prerequisites.
    public function assertReady(string $serviceCode,string $requestId): void {}
}

final class ServiceRequestsTestRepProfile implements RepresentativeProfileValidator
{
    public function assertValid(string $serviceCode,array $profile): void
    {
        if (!is_string($profile['name'] ?? null) || trim($profile['name'])==='') {
            throw new \InvalidArgumentException('Representative name is required.');
        }
    }
}

final class ServiceRequestsTestAudit implements RequestAudit
{
    public array $records = [];
    public function connectionName(): ?string { return \DB::connection()->getName(); }
    public function record(string $event,string $requestId,array $safeMetadata=[]): void
    {
        $this->records[] = ['event_code'=>$event,'resource_id'=>$requestId,'metadata'=>$safeMetadata];
    }
}
