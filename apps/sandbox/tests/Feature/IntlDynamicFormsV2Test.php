<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Intl\AuthorizationLaravel\Bridge\WorkflowActorResolver;
use Intl\AuthorizationLaravel\Bridge\WorkflowAuthorizationBridge;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Contracts\ScopeChecker;
use Intl\AuthorizationLaravel\Principal;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\DynamicForms\Definitions\FormDefinition;
use Intl\DynamicForms\Definitions\FormField;
use Intl\DynamicForms\Definitions\FormSection;
use Intl\DynamicForms\Definitions\RepeatableGroupDefinition;
use Intl\DynamicFormsLaravel\Exceptions\AnswerValidationFailed;
use Intl\WorkflowLaravel\Services\DefinitionCatalog;
use Intl\DynamicFormsLaravel\Services\FormAnswers;
use Intl\DynamicFormsLaravel\Services\FormRegistry;
use Intl\ServiceCatalog\Definitions\ServiceDraft;
use Intl\ServiceCatalog\Definitions\WorkflowBinding;
use Intl\ServiceCatalogLaravel\Services\ServiceCatalog;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\WorkflowLaravel\Contracts\ActorResolver;
use Intl\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class IntlDynamicFormsV2Test extends TestCase
{
    use RefreshDatabase;

    private V2TestAccount $account;
    private V2TestPrincipalProvider $principal;
    private V2TestScopes $scopes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'platform_admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }
        foreach ([
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.view', 'catalog.service.use',
            'workflow.definition.publish', 'forms.definition.edit', 'forms.definition.view',
            'forms.definition.publish', 'forms.response.submit', 'forms.response.view',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }
        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'catalog.service.create', 'catalog.service.activate', 'catalog.service.view',
            'workflow.definition.publish', 'forms.definition.edit', 'forms.definition.view', 'forms.definition.publish',
        ]);
        Role::findByName('agency', 'web')->givePermissionTo(['catalog.service.use', 'forms.response.submit', 'forms.response.view']);

        $this->account = V2TestAccount::create([
            'name' => 'V2 Tester', 'email' => 'v2@example.test', 'password' => 'not-used',
        ]);
        $this->principal = new V2TestPrincipalProvider();
        $this->scopes = new V2TestScopes();
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
            'id' => 'ncr.workflow', 'version' => 1, 'initial_state' => 'draft',
            'states' => ['draft', 'submitted'],
            'transitions' => [['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']]],
        ]));
        $catalog = $this->app->make(ServiceCatalog::class);
        $created = $catalog->create(new ServiceDraft('ncr', 'National Registration', null, [], new WorkflowBinding('ncr.workflow', 1)));
        $catalog->activate('ncr', $created->revision);
    }

    private function registry(): FormRegistry
    {
        return $this->app->make(FormRegistry::class);
    }

    private function responses(): FormAnswers
    {
        return $this->app->make(FormAnswers::class);
    }

    // 1. v2 definition persistence and retrieval round-trip
    public function test_v2_definition_persistence_and_retrieval_round_trip(): void
    {
        $formV2 = new FormDefinition(
            'ncr',
            1,
            [
                new FormField('first_name', 'First Name', 'text', true),
                new FormField('last_name', 'Last Name', 'text', true),
            ],
            [
                new FormSection('personal', 'Personal', null, [
                    ['kind' => 'field', 'key' => 'first_name'],
                    ['kind' => 'field', 'key' => 'last_name'],
                ]),
                new FormSection('family', 'Family', null, [
                    ['kind' => 'repeatable_group', 'key' => 'children'],
                ]),
            ],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 0, 10, [
                    new FormField('child_name', 'Child Name', 'text', true),
                ]),
            ]
        );

        $revision = $this->registry()->saveDraft($formV2);
        $published = $this->registry()->publishDraft('ncr', $revision);

        self::assertSame(1, $published->version);
        self::assertCount(2, $published->fields);
        self::assertCount(2, $published->sections);
        self::assertCount(1, $published->repeatableGroups);

        $loaded = $this->registry()->published('ncr', 1);
        self::assertEquals($published, $loaded);
    }

    // 2. NCR capability scenario: create draft, answer fields, add children, persist, reorder, verify stable item IDs
    public function test_ncr_capability_scenario_with_repeatable_groups_and_reordering(): void
    {
        $formV2 = new FormDefinition(
            'ncr',
            1,
            [
                new FormField('first_name', 'Applicant First Name', 'text', true),
                new FormField('last_name', 'Applicant Last Name', 'text', true),
                new FormField('marital_status', 'Marital Status', 'select', true, ['single', 'married']),
            ],
            [
                new FormSection('applicant', 'Applicant Info', null, [
                    ['kind' => 'field', 'key' => 'first_name'],
                    ['kind' => 'field', 'key' => 'last_name'],
                    ['kind' => 'field', 'key' => 'marital_status'],
                ]),
                new FormSection('family', 'Family Members', null, [
                    ['kind' => 'repeatable_group', 'key' => 'spouses'],
                    ['kind' => 'repeatable_group', 'key' => 'children'],
                ]),
            ],
            [
                new RepeatableGroupDefinition('spouses', 'Spouses', null, 0, 2, [
                    new FormField('spouse_name', 'Spouse Name', 'text', true),
                    new FormField('national_id', 'National ID', 'text', true),
                ]),
                new RepeatableGroupDefinition('children', 'Children', null, 0, 10, [
                    new FormField('first_name', 'Child First Name', 'text', true),
                    new FormField('last_name', 'Child Last Name', 'text', true),
                    new FormField('has_passport', 'Has Passport', 'checkbox', true),
                    new FormField('passport_number', 'Passport Number', 'text', true, visibleWhen: ['field' => 'has_passport', 'equals' => true]),
                ]),
            ]
        );

        $revision = $this->registry()->saveDraft($formV2);
        $this->registry()->publishDraft('ncr', $revision);

        $this->asRole('agency', 'agency-a');

        // Step 1: Save initial draft with ordinary fields and 2 children (without explicit _item_id, system assigns stable IDs)
        $input1 = [
            'first_name' => 'Reza',
            'last_name' => 'Ahmadi',
            'marital_status' => 'married',
            'spouses' => [],
            'children' => [
                ['first_name' => 'Sara', 'last_name' => 'Ahmadi', 'has_passport' => true, 'passport_number' => 'P998877'],
                ['first_name' => 'Ali', 'last_name' => 'Ahmadi', 'has_passport' => false],
            ],
        ];

        $saved1 = $this->responses()->save('ncr', 'form.request', 'owned-1', $input1);
        self::assertSame(0, $saved1['revision']);
        $children1 = $saved1['answers']['children'];
        self::assertCount(2, $children1);

        $idSara = $children1[0]['_item_id'];
        $idAli = $children1[1]['_item_id'];
        self::assertNotEmpty($idSara);
        self::assertNotEmpty($idAli);
        self::assertNotEquals($idSara, $idAli);

        // Step 2: Reorder children (Ali first, Sara second) and update Sara's passport number while preserving their _item_ids
        $input2 = [
            'first_name' => 'Reza',
            'last_name' => 'Ahmadi',
            'marital_status' => 'married',
            'spouses' => [],
            'children' => [
                ['_item_id' => $idAli, 'first_name' => 'Ali', 'last_name' => 'Ahmadi', 'has_passport' => false],
                ['_item_id' => $idSara, 'first_name' => 'Sara', 'last_name' => 'Ahmadi', 'has_passport' => true, 'passport_number' => 'P998877-UPDATED'],
            ],
        ];

        $saved2 = $this->responses()->save('ncr', 'form.request', 'owned-1', $input2, 0);
        self::assertSame(1, $saved2['revision']);

        $children2 = $saved2['answers']['children'];
        self::assertSame($idAli, $children2[0]['_item_id']);
        self::assertSame('Ali', $children2[0]['first_name']);

        self::assertSame($idSara, $children2[1]['_item_id']);
        self::assertSame('P998877-UPDATED', $children2[1]['passport_number']);

        // Step 3: Verify child-level conditional validation works independently (if Ali sets has_passport=true without passport_number, fails validation)
        $invalidInput = [
            'first_name' => 'Reza',
            'last_name' => 'Ahmadi',
            'marital_status' => 'married',
            'spouses' => [],
            'children' => [
                ['_item_id' => $idAli, 'first_name' => 'Ali', 'last_name' => 'Ahmadi', 'has_passport' => true], // missing required passport_number
                ['_item_id' => $idSara, 'first_name' => 'Sara', 'last_name' => 'Ahmadi', 'has_passport' => true, 'passport_number' => 'P998877-UPDATED'],
            ],
        ];

        try {
            $this->responses()->save('ncr', 'form.request', 'owned-1', $invalidInput, 1);
            $this->fail('Should have failed due to missing passport_number on child Ali');
        } catch (AnswerValidationFailed $e) {
            self::assertArrayHasKey("children.{$idAli}.passport_number", $e->errors);
        }
    }

    // 3. New form version may introduce repeatable group without altering prior version answer sets
    public function test_new_form_version_with_repeatable_groups_does_not_mutate_prior_version_answers(): void
    {
        // Version 1: flat form
        $v1 = new FormDefinition('ncr', 1, [
            new FormField('full_name', 'Full Name', 'text', true),
        ]);
        $rev1 = $this->registry()->saveDraft($v1);
        $this->registry()->publishDraft('ncr', $rev1);

        $this->asRole('agency', 'agency-a');
        $saved1 = $this->responses()->save('ncr', 'form.request', 'owned-1', ['full_name' => 'Alice']);
        self::assertSame(1, $saved1['version']);

        // Platform admin publishes Version 2 with repeatable groups
        $this->asRole('platform_admin', 'platform');
        $v2 = new FormDefinition('ncr', 2, [
            new FormField('full_name', 'Full Name', 'text', true),
        ], [], [
            new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                new FormField('name', 'Child Name', 'text', true),
            ]),
        ]);
        $rev2 = $this->registry()->saveDraft($v2);
        $this->registry()->publishDraft('ncr', $rev2);

        // Agency updates owned-1 (pinned to version 1)
        $this->asRole('agency', 'agency-a');
        $editedV1 = $this->responses()->save('ncr', 'form.request', 'owned-1', ['full_name' => 'Alice Smith'], 0);
        self::assertSame(1, $editedV1['version']);

        // Agency starts new submission owned-2 (uses current version 2)
        $saved2 = $this->responses()->save('ncr', 'form.request', 'owned-2', [
            'full_name' => 'Bob',
            'children' => [
                ['name' => 'Charlie'],
            ],
        ]);
        self::assertSame(2, $saved2['version']);
        self::assertCount(1, $saved2['answers']['children']);
    }
}

final class V2TestAccount extends BaseUser
{
    use HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class V2TestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class V2TestScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($request->resourceType === 'workflow.definition') {
            return $actor->role === 'platform_admin';
        }
        if ($request->resourceType === 'forms.definition') {
            return in_array($actor->role, ['platform_admin', 'government_admin'], true);
        }
        if ($request->resourceType === 'catalog.service') {
            return $actor->role === 'agency'
                ? $actor->organizationId === 'agency-a' && $request->resourceId === 'ncr'
                : $actor->role === 'platform_admin';
        }
        if ($request->resourceType === 'form.request') {
            return $actor->role === 'agency' && $actor->organizationId === 'agency-a'
                && in_array($request->resourceId, ['owned-1', 'owned-2'], true);
        }
        return false;
    }
}
