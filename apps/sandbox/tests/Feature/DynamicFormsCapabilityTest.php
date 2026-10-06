<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Bridges\WorkflowActorResolver;
use App\Bridges\WorkflowAuthorizationBridge;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Definitions\FormField;
use Karband\DynamicForms\Exceptions\InvalidForm;
use Karband\DynamicFormsLaravel\Exceptions\AnswerValidationFailed;
use Karband\DynamicFormsLaravel\Exceptions\FormConflict;
use Karband\DynamicFormsLaravel\Exceptions\FormNotFound;
use Karband\DynamicFormsLaravel\Services\FormAnswers;
use Karband\DynamicFormsLaravel\Services\FormRegistry;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

/** Integration test: local isolated SQLite + installed Workflow/Catalog/Authorization. */
final class DynamicFormsCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private FormsTestAccount $account;
    private FormsTestPrincipalProvider $principal;
    private FormsTestScopes $scopes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }
        foreach ([
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.deactivate', 'catalog.service.view', 'catalog.service.use',
            'workflow.definition.publish', 'forms.definition.edit', 'forms.definition.view',
            'forms.definition.publish', 'forms.response.submit', 'forms.response.view',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }
        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.deactivate', 'catalog.service.view',
            'workflow.definition.publish', 'forms.definition.edit', 'forms.definition.view', 'forms.definition.publish',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo(['forms.definition.view', 'forms.response.view']);
        Role::findByName('agency', 'web')->givePermissionTo(['catalog.service.use', 'forms.response.submit', 'forms.response.view']);

        $this->account = FormsTestAccount::create([
            'name'=>'Forms tester', 'email'=>'forms@example.test', 'password'=>'not-used',
        ]);
        $this->principal = new FormsTestPrincipalProvider();
        $this->scopes = new FormsTestScopes();
        $this->app->instance(PrincipalProvider::class, $this->principal);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->asRole('platform_admin', 'platform');
        $this->createCatalogService();
    }

    private function asRole(string $role, ?string $organization): void
    {
        $this->account->syncRoles([$role]);
        Auth::guard('web')->setUser($this->account);
        $actor = new ActorContext((string) $this->account->getAuthIdentifier(), $role, $organization);
        $this->principal->principal = new Principal($this->account, $actor, 'web');
    }

    private function createCatalogService(): void
    {
        $this->app->make(DefinitionCatalog::class)->publish(WorkflowDefinition::fromArray([
            'id'=>'benefit.workflow', 'version'=>1, 'initial_state'=>'draft',
            'states'=>['draft', 'submitted'],
            'transitions'=>[['action'=>'submit', 'from'=>'draft', 'to'=>'submitted', 'allowed_roles'=>['agency']]],
        ]));
        $catalog = $this->app->make(ServiceCatalog::class);
        $created = $catalog->create(new ServiceDraft('benefit', 'Benefit', null, [], new WorkflowBinding('benefit.workflow', 1)));
        $catalog->activate('benefit', $created->revision);
    }

    private function schema(int $version = 1, bool $extra = false): FormDefinition
    {
        $fields = [
            new FormField('name', 'Full name', 'text', true, maxLength: 40),
            new FormField('urgent', 'Urgent?', 'checkbox', true),
            new FormField('reason', 'Reason', 'textarea', true, visibleWhen:['field'=>'urgent','equals'=>true]),
            new FormField('delivery', 'Delivery', 'select', true, ['post', 'pickup']),
        ];
        if ($extra) {
            $fields[] = new FormField('email', 'Email', 'email', true);
        }
        return new FormDefinition('benefit', $version, $fields);
    }

    private function answers(bool $urgent = false): array
    {
        return array_merge(['name'=>'Ava', 'urgent'=>$urgent, 'delivery'=>'post'], $urgent ? ['reason'=>'Immediate need'] : []);
    }

    private function registry(): FormRegistry
    {
        return $this->app->make(FormRegistry::class);
    }

    private function responses(): FormAnswers
    {
        return $this->app->make(FormAnswers::class);
    }

    private function publish(int $version = 1, bool $extra = false): FormDefinition
    {
        $revision = $this->registry()->saveDraft($this->schema($version, $extra));
        return $this->registry()->publishDraft('benefit', $revision);
    }

    public function test_admin_creates_and_publishes_versioned_form(): void
    {
        $revision = $this->registry()->saveDraft($this->schema());
        self::assertSame(0, $revision);
        self::assertSame(1, $this->registry()->draft('benefit')['form']->version);
        $form = $this->registry()->publishDraft('benefit', 0);
        self::assertSame(1, $form->version);
        self::assertSame($form->toArray(), $this->registry()->published('benefit', 1)->toArray());
        $this->assertDatabaseCount('form_drafts', 0);
    }

    public function test_only_explicitly_authorized_user_can_edit(): void
    {
        $this->asRole('government_admin', 'government');
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->saveDraft($this->schema());
    }

    public function test_agency_cannot_escalate_with_second_role(): void
    {
        $this->asRole('agency', 'agency-a');
        $this->account->assignRole('platform_admin');
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->saveDraft($this->schema());
    }

    public function test_stale_draft_cannot_overwrite_changes(): void
    {
        $this->registry()->saveDraft($this->schema());
        self::assertSame(1, $this->registry()->saveDraft($this->schema(), 0));
        $this->expectException(FormConflict::class);
        $this->registry()->saveDraft($this->schema(), 0);
    }

    public function test_unregistered_custom_rule_prevents_publication(): void
    {
        $form = new FormDefinition('benefit', 1, [new FormField('code', 'Code', 'text', rule:'not.registered')]);
        $this->registry()->saveDraft($form);
        try {
            $this->registry()->publishDraft('benefit', 0);
            $this->fail('Missing rule must deny publication.');
        } catch (InvalidForm) {
            $this->assertDatabaseCount('form_definitions', 0);
            $this->assertDatabaseCount('form_drafts', 1);
        }
    }

    public function test_published_version_cannot_be_reused(): void
    {
        $this->publish();
        $this->expectException(FormConflict::class);
        $this->registry()->saveDraft($this->schema());
    }

    public function test_agency_gets_form_for_active_entitled_service(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-a');
        self::assertSame(1, $this->registry()->currentForAgency('benefit')->version);
    }

    public function test_foreign_agency_cannot_discover_form(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-b');
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->currentForAgency('benefit');
    }

    public function test_submitting_answers_pins_form_version(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-a');
        $saved = $this->responses()->save('benefit', 'form.request', 'owned-1', $this->answers());
        self::assertSame(1, $saved['version']);
        self::assertSame(0, $saved['revision']);
        self::assertSame($this->answers(), $this->responses()->read('benefit', 'form.request', 'owned-1')['answers']);
    }

    public function test_invalid_answer_rolls_back_without_mutation(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-a');
        try {
            $this->responses()->save('benefit','form.request','owned-1', [...$this->answers(), 'elevated'=>true]);
            $this->fail('Unexpected field must be rejected.');
        } catch (AnswerValidationFailed $e) {
            self::assertArrayHasKey('elevated', $e->errors);
            $this->assertDatabaseCount('form_answers', 0);
        }
    }

    public function test_hidden_field_cannot_be_submitted(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-a');
        $this->expectException(AnswerValidationFailed::class);
        $this->responses()->save('benefit','form.request','owned-1',[...$this->answers(), 'reason'=>'hidden value']);
    }

    public function test_foreign_agency_cannot_modify_or_read_resource(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-a');
        $this->responses()->save('benefit','form.request','owned-1',$this->answers());
        $this->asRole('agency','agency-b');
        $this->expectException(AuthorizationDenied::class);
        $this->responses()->read('benefit','form.request','owned-1');
    }

    public function test_foreign_resource_cannot_receive_new_submission(): void
    {
        $this->publish();
        $this->asRole('agency','agency-a');
        $this->expectException(AuthorizationDenied::class);
        $this->responses()->save('benefit','form.request','foreign',$this->answers());
    }

    public function test_stale_answer_revision_cannot_overwrite(): void
    {
        $this->publish();
        $this->asRole('agency','agency-a');
        $this->responses()->save('benefit','form.request','owned-1',$this->answers());
        $second = $this->responses()->save('benefit','form.request','owned-1',
            [...$this->answers(), 'name'=>'New'], 0);
        self::assertSame(1, $second['revision']);
        $this->expectException(FormConflict::class);
        $this->responses()->save('benefit','form.request','owned-1',$this->answers(),0);
    }

    public function test_form_version_is_pinned_when_admin_publishes_new_version(): void
    {
        $this->publish();
        $this->asRole('agency','agency-a');
        $this->responses()->save('benefit','form.request','owned-1',$this->answers());
        $this->asRole('platform_admin','platform');
        $this->publish(2, true);
        $this->asRole('agency','agency-a');
        $edited = $this->responses()->save('benefit','form.request','owned-1',
            [...$this->answers(), 'name'=>'Renamed'], 0);
        self::assertSame(1, $edited['version']);
        self::assertSame(2, $this->registry()->currentForAgency('benefit')->version);
        try {
            $this->responses()->save('benefit','form.request','owned-2',$this->answers());
            $this->fail('New instance must require the new email field.');
        } catch (AnswerValidationFailed $e) {
            self::assertArrayHasKey('email', $e->errors);
        }
    }

    public function test_reserve_pins_form_before_first_answer_even_after_publication(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-a');
        $reserved = $this->responses()->reserve('benefit', 'form.request', 'owned-1');
        self::assertSame(1, $reserved['version']);
        self::assertSame(0, $reserved['revision']);
        $this->asRole('platform_admin', 'platform');
        $this->publish(2, true);
        $this->asRole('agency', 'agency-a');
        $saved = $this->responses()->save('benefit', 'form.request', 'owned-1', $this->answers(), 0);
        self::assertSame(1, $saved['version']);
        self::assertSame(2, $this->registry()->currentForAgency('benefit')->version);
    }

    public function test_reserve_same_resource_twice_is_rejected(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-a');
        $this->responses()->reserve('benefit','form.request','owned-1');
        $this->expectException(FormConflict::class);
        $this->responses()->reserve('benefit','form.request','owned-1');
    }

    public function test_inactive_service_blocks_new_answer_sets_but_allows_existing_edits(): void
    {
        $this->publish();
        $this->asRole('agency','agency-a');
        $this->responses()->save('benefit','form.request','owned-1',$this->answers());
        $this->asRole('platform_admin','platform');
        $service = $this->app->make(ServiceCatalog::class)->get('benefit');
        $this->app->make(ServiceCatalog::class)->deactivate('benefit', $service->revision);
        $this->asRole('agency','agency-a');
        $this->expectException(FormNotFound::class);
        $this->responses()->save('benefit','form.request','owned-2',$this->answers());
    }

    public function test_existing_answer_set_keeps_pinned_form_after_service_deactivation(): void
    {
        $this->publish();
        $this->asRole('agency','agency-a');
        $this->responses()->save('benefit','form.request','owned-1',$this->answers());
        $this->asRole('platform_admin','platform');
        $service = $this->app->make(ServiceCatalog::class)->get('benefit');
        $this->app->make(ServiceCatalog::class)->deactivate('benefit', $service->revision);
        $this->asRole('agency','agency-a');
        self::assertSame(1, $this->responses()->save('benefit','form.request','owned-1',
            [...$this->answers(), 'name'=>'Edit'], 0)['revision']);
    }

    public function test_government_can_read_but_cannot_submit_answers(): void
    {
        $this->publish();
        $this->asRole('agency','agency-a');
        $this->responses()->save('benefit','form.request','owned-1',$this->answers());
        $this->asRole('government_admin','government');
        self::assertSame(1, $this->responses()->read('benefit','form.request','owned-1')['version']);
        $this->expectException(AuthorizationDenied::class);
        $this->responses()->save('benefit','form.request','owned-1',$this->answers(),0);
    }

    public function test_scope_exception_denies_access(): void
    {
        $this->publish();
        $this->asRole('agency','agency-a');
        $this->scopes->throw = true;
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->currentForAgency('benefit');
    }

    public function test_missing_principal_denies_access(): void
    {
        $this->principal->principal = null;
        $this->expectException(\RuntimeException::class);
        $this->registry()->saveDraft($this->schema());
    }

    public function test_stored_definition_tamper_is_detected(): void
    {
        $this->publish();
        DB::table('form_definitions')->where('service_code','benefit')->where('version',1)
            ->update(['definition'=>json_encode($this->schema(2)->toArray(), JSON_THROW_ON_ERROR)]);
        $this->expectException(\UnexpectedValueException::class);
        $this->registry()->published('benefit',1);
    }
}

final class FormsTestAccount extends BaseUser
{
    use HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class FormsTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class FormsTestScopes implements ScopeChecker
{
    public bool $throw = false;

    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($this->throw) {
            throw new \RuntimeException('Scope repository unavailable');
        }
        if ($request->resourceType === 'workflow.definition') {
            return $actor->role === 'platform_admin';
        }
        if ($request->resourceType === 'forms.definition') {
            return in_array($actor->role, ['platform_admin','government_admin'], true);
        }
        if ($request->resourceType === 'catalog.service') {
            return $actor->role === 'agency'
                ? $actor->organizationId === 'agency-a' && $request->resourceId === 'benefit'
                : $actor->role === 'platform_admin';
        }
        if ($request->resourceType === 'form.request') {
            if ($actor->role === 'government_admin') {
                return true;
            }
            return $actor->role === 'agency' && $actor->organizationId === 'agency-a'
                && in_array($request->resourceId, ['owned-1','owned-2'], true);
        }
        return false;
    }
}
