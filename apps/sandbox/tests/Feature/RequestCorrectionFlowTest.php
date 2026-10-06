<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use App\Bridges\WorkflowActorResolver;
use App\Bridges\WorkflowAuthorizationBridge;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Documents\Definitions\DocumentDefinition;
use Karband\DocumentsLaravel\Services\DocumentRegistry;
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Definitions\FormField;
use Karband\DynamicForms\Definitions\RepeatableGroupDefinition;
use Karband\DynamicFormsLaravel\Services\FormRegistry;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\ServiceRequests\Policies\RequestPolicy;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequests\ValueObjects\RequestDraft;
use Karband\DocumentsLaravel\Contracts\FileSafetyScanner;
use Karband\ServiceRequestsLaravel\Contracts\RecipientVerifier;
use Karband\DocumentsLaravel\Contracts\ReplacementPolicy;
use Karband\ServiceRequestsLaravel\Contracts\RequestAudit;
use Karband\ServiceRequestsLaravel\Services\RequestLifecycle;
use Karband\ServiceRequestsLaravel\Services\ServiceRequests;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class RequestCorrectionFlowTest extends TestCase
{
    use RefreshDatabase;

    private StructCorrAccount $account;
    private StructCorrPrincipal $principal;
    private StructCorrScope $scopes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }
        foreach ([
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.deactivate', 'catalog.service.view', 'catalog.service.use',
            'workflow.definition.publish', 'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit',
            'workflow.action.require_correction', 'workflow.action.resubmit', 'workflow.action.approve', 'workflow.action.cancel_draft',
            'forms.definition.edit', 'forms.definition.view', 'forms.definition.publish', 'forms.response.submit', 'forms.response.view',
            'documents.definition.edit', 'documents.definition.publish', 'documents.file.upload', 'documents.file.view',
            'requests.create', 'requests.view', 'requests.correction.issue', 'requests.correction.resubmit',
            'requests.cancel.withdraw', 'requests.draft.delete', 'requests.representative.select',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.deactivate', 'catalog.service.view',
            'workflow.definition.publish', 'forms.definition.edit', 'forms.definition.view', 'forms.definition.publish',
            'documents.definition.edit', 'documents.definition.publish', 'requests.view',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'forms.definition.view', 'forms.response.view', 'requests.view', 'requests.correction.issue',
            'workflow.action.require_correction', 'workflow.action.approve',
        ]);
        Role::findByName('agency', 'web')->givePermissionTo([
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit', 'workflow.action.resubmit', 'workflow.action.cancel_draft',
            'catalog.service.use', 'forms.response.submit', 'forms.response.view', 'documents.file.upload', 'documents.file.view',
            'requests.create', 'requests.view', 'requests.correction.resubmit', 'requests.cancel.withdraw', 'requests.draft.delete',
            'requests.representative.select',
        ]);

        $this->account = StructCorrAccount::create([
            'name' => 'Structured Corr Tester', 'email' => 'sc@example.test', 'password' => 'not-used',
        ]);
        $this->principal = new StructCorrPrincipal();
        $this->scopes = new StructCorrScope();
        $this->app->instance(PrincipalProvider::class, $this->principal);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->app->instance(RecipientVerifier::class, new StructCorrRecipients());
        $this->app->instance(ReplacementPolicy::class, new StructCorrReplacement());
        $this->app->instance(FileSafetyScanner::class, new StructCorrScanner());
        $this->app->instance(\Karband\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier::class, new StructCorrSubmission());
        $this->app->instance(RequestAudit::class, new StructCorrAudit());

        $this->seedRecipient('customer-1', 'person', 'agency-a');

        $this->asRole('platform_admin', 'platform');
        $this->setupServiceAndForm();
    }

    private function seedRecipient(string $id, string $kind, string $agency): void
    {
        \Illuminate\Support\Facades\DB::table('recipients')->insert([
            'id' => $id, 'agency_id' => $agency, 'kind' => $kind,
            'identity_type' => 'test_code',
            'identity_lookup' => hash('sha256', $agency . '/' . $kind . '/' . $id),
            'identity_cipher' => \Illuminate\Support\Facades\Crypt::encryptString('identity-' . $id),
            'profile_cipher' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['full_name' => 'Alice', 'nickname' => 'Al'], JSON_THROW_ON_ERROR)),
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

    private function setupServiceAndForm(): void
    {
        $this->app->make(DefinitionCatalog::class)->publish(WorkflowDefinition::fromArray([
            'id' => 'ncr.workflow', 'version' => 1, 'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'correction_required'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'require_correction', 'from' => 'submitted', 'to' => 'correction_required', 'allowed_roles' => ['government_admin']],
                ['action' => 'resubmit', 'from' => 'correction_required', 'to' => 'submitted', 'allowed_roles' => ['agency']],
            ],
        ]));

        $policy = [
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
            'recipient_field_map' => ['full_name' => 'applicant_name'],
        ];

        $catalog = app(ServiceCatalog::class);
        $created = $catalog->create(new ServiceDraft('ncr', 'NCR Service', null, ['request_management' => $policy], new WorkflowBinding('ncr.workflow', 1)));

        $formV2 = new FormDefinition('ncr', 1, [
            new FormField('applicant_name', 'Applicant Name', 'text', true),
        ], [], [
            new RepeatableGroupDefinition('children', 'Children', null, 0, 10, [
                new FormField('first_name', 'First Name', 'text', true),
                new FormField('national_id', 'National ID', 'text', true),
                new FormField('has_passport', 'Has Passport', 'checkbox', true),
                new FormField('passport_number', 'Passport Number', 'text', true, visibleWhen: ['field' => 'has_passport', 'equals' => true]),
            ]),
        ]);

        $formReg = app(FormRegistry::class);
        $rev = $formReg->saveDraft($formV2);
        $formReg->publishDraft('ncr', $rev);

        $docReg = app(DocumentRegistry::class);
        $docRev = $docReg->saveDraft(new DocumentDefinition('ncr', 1, []));
        $docReg->publishDraft('ncr', $docRev);

        $catalog->activate('ncr', $created->revision);
    }

    private function registry(): ServiceRequests
    {
        return app(ServiceRequests::class);
    }

    private function draft(): RequestDraft
    {
        return new RequestDraft('ncr', new RecipientReference('person', 'customer-1'), 'browser-1');
    }

    // Scenario A: Exact child correction (children.child_A.national_id)
    public function test_scenario_a_exact_child_correction(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create($this->draft());
        $id = $req->id;

        $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
            ],
        ], 0);

        $this->registry()->act($id, 'submit');

        // Government requires limited correction on ONLY children.child_A.national_id
        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($id, 'Correct Sara national ID', ['children.child_A.national_id']);

        $this->asRole('agency', 'agency-a');

        // Modifying child_B at the same time fails
        try {
            $this->registry()->saveAnswers($id, [
                'applicant_name' => 'John',
                'children' => [
                    ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111-updated', 'has_passport' => false],
                    ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222-updated', 'has_passport' => false],
                ],
            ], 1);
            $this->fail('Modifying child_B national_id must fail under exact child_A scope');
        } catch (\DomainException) {
            self::assertTrue(true);
        }

        // Modifying only child_A national_id succeeds
        $saved = $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111-updated', 'has_passport' => false],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
            ],
        ], 1);

        self::assertSame(2, $saved['revision']);
        self::assertSame('111-updated', $saved['answers']['children'][0]['national_id']);
    }

    // Scenario B: Wildcard field correction (children.*.national_id)
    public function test_scenario_b_wildcard_field_correction(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create($this->draft());
        $id = $req->id;

        $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
            ],
        ], 0);

        $this->registry()->act($id, 'submit');

        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($id, 'Update national IDs', ['children.*.national_id']);

        $this->asRole('agency', 'agency-a');

        // Renaming child_A fails
        try {
            $this->registry()->saveAnswers($id, [
                'applicant_name' => 'John',
                'children' => [
                    ['_item_id' => 'child_A', 'first_name' => 'Sara-Renamed', 'national_id' => '111-new', 'has_passport' => false],
                    ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222-new', 'has_passport' => false],
                ],
            ], 1);
            $this->fail('Renaming child_A must fail under children.*.national_id scope');
        } catch (\DomainException) {
            self::assertTrue(true);
        }

        // Updating national_id on both children succeeds
        $saved = $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111-new', 'has_passport' => false],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222-new', 'has_passport' => false],
            ],
        ], 1);

        self::assertSame('111-new', $saved['answers']['children'][0]['national_id']);
        self::assertSame('222-new', $saved['answers']['children'][1]['national_id']);
    }

    // Scenario C: Addition (children._add)
    public function test_scenario_c_addition(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create($this->draft());
        $id = $req->id;

        $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
            ],
        ], 0);

        $this->registry()->act($id, 'submit');

        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($id, 'Add second child', ['children._add']);

        $this->asRole('agency', 'agency-a');

        // Editing existing child_A while adding child_B fails
        try {
            $this->registry()->saveAnswers($id, [
                'applicant_name' => 'John',
                'children' => [
                    ['_item_id' => 'child_A', 'first_name' => 'Sara-Edited', 'national_id' => '111', 'has_passport' => false],
                    ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
                ],
            ], 1);
            $this->fail('Editing existing child_A must fail under children._add scope');
        } catch (\DomainException) {
            self::assertTrue(true);
        }

        // Adding child_B with child_A untouched succeeds
        $saved = $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
            ],
        ], 1);

        self::assertCount(2, $saved['answers']['children']);
    }

    // Scenario D: Removal (children.child_A._remove)
    public function test_scenario_d_removal(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create($this->draft());
        $id = $req->id;

        $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
            ],
        ], 0);

        $this->registry()->act($id, 'submit');

        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($id, 'Remove child_A', ['children.child_A._remove']);

        $this->asRole('agency', 'agency-a');

        // Removing child_B instead fails
        try {
            $this->registry()->saveAnswers($id, [
                'applicant_name' => 'John',
                'children' => [
                    ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
                ],
            ], 1);
            $this->fail('Removing child_B must fail under children.child_A._remove scope');
        } catch (\DomainException) {
            self::assertTrue(true);
        }

        // Removing child_A succeeds
        $saved = $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
            ],
        ], 1);

        self::assertCount(1, $saved['answers']['children']);
        self::assertSame('child_B', $saved['answers']['children'][0]['_item_id']);
    }

    // Scenario E: Reorder only requires 0 permissions
    public function test_scenario_e_reorder_only(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create($this->draft());
        $id = $req->id;

        $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
            ],
        ], 0);

        $this->registry()->act($id, 'submit');

        $this->asRole('government_admin', 'government');
        // Admin unlocks an unrelated top-level field (e.g. applicant_name), no child permissions given
        app(RequestLifecycle::class)->requireCorrection($id, 'Fix applicant name', ['applicant_name']);

        $this->asRole('agency', 'agency-a');

        // Reordering children while fixing applicant_name succeeds!
        $saved = $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John Smith',
            'children' => [
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '222', 'has_passport' => false],
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
            ],
        ], 1);

        self::assertSame('John Smith', $saved['answers']['applicant_name']);
        self::assertSame('child_B', $saved['answers']['children'][0]['_item_id']);
    }

    // Scenario F: Identity replacement attack
    public function test_scenario_f_identity_replacement_attack(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create($this->draft());
        $id = $req->id;

        $this->registry()->saveAnswers($id, [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '111', 'has_passport' => false],
            ],
        ], 0);

        $this->registry()->act($id, 'submit');

        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($id, 'Update national ID', ['children.*.national_id']);

        $this->asRole('agency', 'agency-a');

        // Replacing _item_id 'child_A' with 'child_HACK' to bypass exact scope fails
        try {
            $this->registry()->saveAnswers($id, [
                'applicant_name' => 'John',
                'children' => [
                    ['_item_id' => 'child_HACK', 'first_name' => 'Sara', 'national_id' => '111-updated', 'has_passport' => false],
                ],
            ], 1);
            $this->fail('Identity replacement attack must fail without add and remove permissions');
        } catch (\DomainException) {
            self::assertTrue(true);
        }
    }

    // NCR-Derived Reference Scenario
    public function test_ncr_derived_structured_correction_scenario(): void
    {
        $this->asRole('agency', 'agency-a');
        $req = $this->registry()->create($this->draft());
        $id = $req->id;

        // Starting request: children A and B
        $this->registry()->saveAnswers($id, [
            'applicant_name' => 'Reza',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '100', 'has_passport' => true, 'passport_number' => 'P100'],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '200', 'has_passport' => true, 'passport_number' => 'P200'],
            ],
        ], 0);

        $this->registry()->act($id, 'submit');

        // Admin requires correction unlocking: children.*.national_id and children.child_A.passport_number
        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($id, 'NCR Correction', [
            'children.*.national_id',
            'children.child_A.passport_number',
        ]);

        $this->asRole('agency', 'agency-a');

        // 1. Both children's national_id may change, and child_A's passport_number may change
        $saved = $this->registry()->saveAnswers($id, [
            'applicant_name' => 'Reza',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '100-NEW', 'has_passport' => true, 'passport_number' => 'P100-NEW'],
                ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '200-NEW', 'has_passport' => true, 'passport_number' => 'P200'],
            ],
        ], 1);

        self::assertSame('100-NEW', $saved['answers']['children'][0]['national_id']);
        self::assertSame('P100-NEW', $saved['answers']['children'][0]['passport_number']);
        self::assertSame('200-NEW', $saved['answers']['children'][1]['national_id']);

        // Resubmit request
        app(RequestLifecycle::class)->resubmit($id);

        // 2. Second round: test that child B's locked passport_number cannot change
        $this->asRole('government_admin', 'government');
        app(RequestLifecycle::class)->requireCorrection($id, 'NCR Second Round', [
            'children.*.national_id',
            'children.child_A.passport_number',
        ]);

        $this->asRole('agency', 'agency-a');
        try {
            $this->registry()->saveAnswers($id, [
                'applicant_name' => 'Reza',
                'children' => [
                    ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '100-NEW', 'has_passport' => true, 'passport_number' => 'P100-NEW'],
                    ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '200-NEW', 'has_passport' => true, 'passport_number' => 'P200-CHANGED'], // locked!
                ],
            ], 2);
            $this->fail('Changing child_B passport_number must fail');
        } catch (\DomainException) {
            self::assertTrue(true);
        }

        // 3. Adding a child fails
        try {
            $this->registry()->saveAnswers($id, [
                'applicant_name' => 'Reza',
                'children' => [
                    ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '100-NEW', 'has_passport' => true, 'passport_number' => 'P100-NEW'],
                    ['_item_id' => 'child_B', 'first_name' => 'Ali', 'national_id' => '200-NEW', 'has_passport' => true, 'passport_number' => 'P200'],
                    ['_item_id' => 'child_C', 'first_name' => 'Charlie', 'national_id' => '300', 'has_passport' => false],
                ],
            ], 2);
            $this->fail('Adding a child must fail');
        } catch (\DomainException) {
            self::assertTrue(true);
        }

        // 4. Removing a child fails
        try {
            $this->registry()->saveAnswers($id, [
                'applicant_name' => 'Reza',
                'children' => [
                    ['_item_id' => 'child_A', 'first_name' => 'Sara', 'national_id' => '100-NEW', 'has_passport' => true, 'passport_number' => 'P100-NEW'],
                ],
            ], 2);
            $this->fail('Removing a child must fail');
        } catch (\DomainException) {
            self::assertTrue(true);
        }
    }
}

final class StructCorrAccount extends BaseUser
{
    use HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class StructCorrPrincipal implements PrincipalProvider
{
    public ?Principal $principal = null;
    public function current(): Principal { return $this->principal ?? throw new \RuntimeException('No authenticated user.'); }
}

final class StructCorrScope implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool { return true; }
}

final class StructCorrRecipients implements RecipientVerifier
{
    public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool { return true; }
}

final class StructCorrReplacement implements ReplacementPolicy
{
    public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool { return false; }
}

final class StructCorrScanner implements FileSafetyScanner
{
    public function assertSafe(string $temporaryPath, string $detectedMimeType): void {}
}

final class StructCorrSubmission implements \Karband\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier
{
    public function assertSubmittable(array $submission): void {}
    public function assertReady(string $serviceCode, string $requestId): void {}
}

final class StructCorrAudit implements RequestAudit
{
    public array $records = [];
    public function connectionName(): ?string { return \DB::connection()->getName(); }
    public function record(string $event, string $requestId, array $safeMetadata = []): void
    {
        $this->records[] = ['event_code' => $event, 'resource_id' => $requestId, 'metadata' => $safeMetadata];
    }
}
