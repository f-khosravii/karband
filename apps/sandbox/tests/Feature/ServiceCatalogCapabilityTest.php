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
use Karband\Authorization\AuthorizationDenied;
use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalog\Exceptions\InvalidService;
use Karband\ServiceCatalogLaravel\Exceptions\ServiceConflict;
use Karband\ServiceCatalogLaravel\Exceptions\ServiceUnavailable;
use Karband\ServiceCatalogLaravel\Exceptions\StaleService;
use App\Bridges\CatalogWorkflowStarter;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
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

/** Must run only inside the isolated SQLite Sandbox (RefreshDatabase). */
final class ServiceCatalogCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private CatalogTestPrincipalProvider $principals;
    private CatalogTestScopes $scopes;
    private CatalogTestAccount $account;
    private CatalogTestAuditRecorder $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        foreach ([
            'catalog.service.create', 'catalog.service.update', 'catalog.service.activate',
            'catalog.service.deactivate', 'catalog.service.archive', 'catalog.service.view',
            'catalog.service.list', 'catalog.service.use',
            'workflow.definition.publish', 'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }
        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'catalog.service.create', 'catalog.service.update', 'catalog.service.activate',
            'catalog.service.deactivate', 'catalog.service.archive', 'catalog.service.view',
            'catalog.service.list', 'workflow.definition.publish',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'catalog.service.view', 'catalog.service.list',
        ]);
        Role::findByName('agency', 'web')->givePermissionTo([
            'catalog.service.list', 'catalog.service.use',
            'workflow.instance.start', 'workflow.instance.view', 'workflow.action.submit',
        ]);

        $this->account = CatalogTestAccount::create([
            'name' => 'Catalog test', 'email' => 'catalog@example.test', 'password' => 'not-used',
        ]);
        $this->principals = new CatalogTestPrincipalProvider();
        $this->scopes = new CatalogTestScopes();
        $this->audit = new CatalogTestAuditRecorder();
        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->app->instance(\Karband\Audit\AuditRecorderInterface::class, $this->audit);
        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->asRole('platform_admin', 'platform');
    }

    private function asRole(string $role, ?string $organization): void
    {
        $this->account->syncRoles([$role]);
        Auth::guard('web')->setUser($this->account);
        $actor = new ActorContext((string) $this->account->getAuthIdentifier(), $role, $organization);
        $this->principals->principal = new Principal($this->account, $actor, 'web');
    }

    private function catalog(): ServiceCatalog
    {
        return $this->app->make(ServiceCatalog::class);
    }

    private function binding(int $version = 1): WorkflowBinding
    {
        return new WorkflowBinding('sample.workflow', $version);
    }

    private function draft(string $code = 'sample', int $version = 1, array $extraSettings = []): ServiceDraft
    {
        $settings = array_merge([
            'internal_label' => 'internal',
            'pricing_mode' => 'fixed_catalog',
            'billing_fixed_price_minor' => 15000,
            'billing_currency' => 'EUR',
            'refund_events' => ['request.rejected' => 'automatic'],
        ], $extraSettings);

        return new ServiceDraft($code, 'Example Service', 'Public description',
            $settings, $this->binding($version));
    }

    private function publish(int $version = 1): void
    {
        $this->asRole('platform_admin', 'platform');
        $this->app->make(DefinitionCatalog::class)->publish(WorkflowDefinition::fromArray([
            'id' => 'sample.workflow', 'version' => $version, 'initial_state' => 'draft',
            'states' => ['draft', 'submitted'],
            'transitions' => [[
                'action' => 'submit', 'from' => 'draft', 'to' => 'submitted',
                'allowed_roles' => ['agency'],
            ]],
        ]));
    }

    private function create(string $code = 'sample', int $version = 1): ServiceDefinition
    {
        $this->publish($version);
        return $this->catalog()->create($this->draft($code, $version));
    }

    private function activate(string $code = 'sample'): ServiceDefinition
    {
        $created = $this->create($code);
        return $this->catalog()->activate($code, $created->revision);
    }

    public function test_creates_inactive_local_service_with_published_workflow(): void
    {
        $created = $this->create();
        $this->assertSame(ServiceStatus::Inactive, $created->status);
        $this->assertSame(1, $this->catalog()->get('sample')->workflow->version);
        $this->assertSame(0, $created->revision);
    }

    public function test_create_rejects_unpublished_binding_without_partial_write(): void
    {
        try {
            $this->catalog()->create($this->draft());
            $this->fail('Unpublished workflow must be rejected.');
        } catch (\RuntimeException) {
            $this->assertDatabaseMissing('catalog_services', ['code' => 'sample']);
        }
    }

    public function test_cannot_reuse_service_code(): void
    {
        $this->create();
        $this->expectException(ServiceConflict::class);
        $this->catalog()->create($this->draft());
    }

    public function test_agency_cannot_create_even_if_it_guesses_code(): void
    {
        $this->asRole('agency', 'agency-a');
        $this->expectException(AuthorizationDenied::class);
        $this->catalog()->create(new ServiceDraft('guess', 'Unauthorized'));
    }

    public function test_government_can_view_local_catalog_but_cannot_create(): void
    {
        $this->create();
        $this->asRole('government_admin', 'gov-local');
        $this->assertSame('sample', $this->catalog()->get('sample')->code);
        $this->expectException(AuthorizationDenied::class);
        $this->catalog()->create(new ServiceDraft('other', 'Other'));
    }

    public function test_agency_does_not_receive_internal_settings_from_available(): void
    {
        $this->activate();
        $this->asRole('agency', 'agency-a');
        $list = $this->catalog()->available();
        $this->assertCount(1, $list);
        $this->assertSame('sample', $list[0]->code);
        $this->assertFalse(property_exists($list[0], 'settings'));
        $this->assertFalse(property_exists($list[0], 'workflow'));
    }

    public function test_agency_cannot_discover_unentitled_services(): void
    {
        $this->activate('foreign');
        $this->asRole('agency', 'agency-a');
        $this->assertSame([], $this->catalog()->available());
        $this->expectException(AuthorizationDenied::class);
        $this->catalog()->requireForUse('foreign');
    }

    public function test_agency_cannot_read_admin_details_without_view_permission(): void
    {
        $this->create();
        $this->asRole('agency', 'agency-a');
        $this->expectException(AuthorizationDenied::class);
        $this->catalog()->get('sample');
    }

    public function test_inactive_service_is_not_available_even_to_entitled_agency(): void
    {
        $this->create();
        $this->asRole('agency', 'agency-a');
        $this->assertSame([], $this->catalog()->available());
        $this->expectException(ServiceUnavailable::class);
        $this->catalog()->requireForUse('sample');
    }

    public function test_active_transition_and_disable_prevent_future_use(): void
    {
        $active = $this->activate();
        $this->assertSame(ServiceStatus::Active, $active->status);
        $disabled = $this->catalog()->deactivate('sample', $active->revision);
        $this->assertSame(ServiceStatus::Inactive, $disabled->status);
        $this->asRole('agency', 'agency-a');
        $this->expectException(ServiceUnavailable::class);
        $this->catalog()->requireForUse('sample');
    }

    public function test_archive_is_final_and_stores_no_delete(): void
    {
        $this->create();
        $archived = $this->catalog()->archive('sample', 0);
        $this->assertSame(ServiceStatus::Archived, $archived->status);
        $this->expectException(InvalidService::class);
        $this->catalog()->activate('sample', $archived->revision);
    }

    public function test_stale_edit_cannot_overwrite_newer_data(): void
    {
        $this->create();
        $updated = $this->catalog()->replace('sample', 0,
            new ServiceDraft('sample', 'New', 'Desc', ['label' => 'v2'], $this->binding()));
        $this->assertSame(1, $updated->revision);
        $this->expectException(StaleService::class);
        $this->catalog()->replace('sample', 0, $this->draft());
    }

    public function test_replacement_cannot_rewrite_immutable_service_code(): void
    {
        $this->create();
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog()->replace('sample', 0, $this->draft('other'));
    }

    public function test_failed_rebinding_leaves_old_revision_unchanged(): void
    {
        $this->create();
        try {
            $this->catalog()->replace('sample', 0, $this->draft('sample', 99));
            $this->fail('Unpublished binding must be rejected.');
        } catch (\RuntimeException) {
            $this->assertSame(0, $this->catalog()->get('sample')->revision);
            $this->assertSame(1, $this->catalog()->get('sample')->workflow->version);
        }
    }

    public function test_published_rebinding_takes_effect_for_new_instances_only(): void
    {
        $active = $this->activate();
        $this->asRole('agency', 'agency-a');
        $first = $this->app->make(CatalogWorkflowStarter::class)
            ->start('sample', 'catalog.request', 'owned-1');
        $this->assertSame(1, $first->workflowVersion);

        $this->publish(2);
        $updated = $this->catalog()->replace('sample', $active->revision, $this->draft('sample', 2));
        $this->assertSame(2, $updated->workflow->version);

        $this->asRole('agency', 'agency-a');
        $second = $this->app->make(CatalogWorkflowStarter::class)
            ->start('sample', 'catalog.request', 'owned-2');
        $this->assertSame(2, $second->workflowVersion);
        $this->assertSame(1, $this->app->make(WorkflowRuntime::class)->view($first->id)->workflowVersion);
    }

    public function test_agency_cannot_start_workflow_for_foreign_resource(): void
    {
        $this->activate();
        $this->asRole('agency', 'agency-a');
        $this->expectException(AuthorizationDenied::class);
        $this->app->make(CatalogWorkflowStarter::class)
            ->start('sample', 'catalog.request', 'foreign-resource');
    }

    public function test_denied_workflow_start_does_not_create_instance(): void
    {
        $this->activate();
        $this->asRole('agency', 'agency-a');
        try {
            $this->app->make(CatalogWorkflowStarter::class)
                ->start('sample', 'catalog.request', 'foreign-resource');
            $this->fail('Foreign start should be denied.');
        } catch (AuthorizationDenied) {
            $this->assertDatabaseCount('workflow_instances', 0);
        }
    }

    public function test_cannot_start_new_workflow_after_deactivation(): void
    {
        $active = $this->activate();
        $this->catalog()->deactivate('sample', $active->revision);
        $this->asRole('agency', 'agency-a');
        $this->expectException(ServiceUnavailable::class);
        $this->app->make(CatalogWorkflowStarter::class)
            ->start('sample', 'catalog.request', 'owned-1');
    }

    public function test_existing_workflow_remains_pinned_after_deactivation(): void
    {
        $active = $this->activate();
        $this->asRole('agency', 'agency-a');
        $existing = $this->app->make(CatalogWorkflowStarter::class)
            ->start('sample', 'catalog.request', 'owned-1');
        $this->asRole('platform_admin', 'platform');
        $this->catalog()->deactivate('sample', $active->revision);
        $this->asRole('agency', 'agency-a');
        $this->assertSame(1, $this->app->make(WorkflowRuntime::class)->view($existing->id)->workflowVersion);
        $this->assertSame('submitted', $this->app->make(WorkflowRuntime::class)
            ->apply($existing->id, 'submit')->instance->currentState);
    }

    public function test_role_assignment_cannot_grant_extra_catalog_permission(): void
    {
        $this->create();
        $this->asRole('agency', 'agency-a');
        $this->account->assignRole('platform_admin');
        $this->expectException(AuthorizationDenied::class);
        $this->catalog()->archive('sample', 0);
    }

    public function test_missing_principal_fails_closed(): void
    {
        $this->principals->principal = null;
        $this->expectException(\RuntimeException::class);
        $this->catalog()->available();
    }

    public function test_scope_exception_fails_closed(): void
    {
        $this->create();
        $this->scopes->throw = true;
        $this->expectException(AuthorizationDenied::class);
        $this->catalog()->get('sample');
    }

    public function test_list_authorized_filters_individual_service_scopes(): void
    {
        $this->create();
        $this->publish(2);
        $this->catalog()->create($this->draft('foreign', 2));
        $this->asRole('government_admin', 'gov-local');
        $this->assertCount(2, $this->catalog()->listAuthorized());
    }

    public function test_invalid_pagination_is_rejected_before_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog()->available(999);
    }

    public function test_activation_fails_if_fixed_catalog_has_invalid_or_missing_financial_terms(): void
    {
        $this->publish(1);
        $created = $this->catalog()->create(new ServiceDraft('incomplete', 'Incomplete Service', null, [
            'pricing_mode' => 'fixed_catalog',
            // Missing billing_fixed_price_minor, billing_currency, refund_events
        ], $this->binding(1)));

        $this->expectException(InvalidService::class);
        $this->catalog()->activate('incomplete', $created->revision);
    }

    public function test_platform_admin_can_update_metadata_and_financial_terms_safely(): void
    {
        $created = $this->create('safe.meta');
        $this->assertSame(0, $created->revision);
        $this->assertSame('Example Service', $created->name);

        // Update metadata
        $updatedMeta = $this->catalog()->updateMetadata('safe.meta', 0, 'Updated Name', 'Updated description');
        $this->assertSame(1, $updatedMeta->revision);
        $this->assertSame('Updated Name', $updatedMeta->name);
        $this->assertSame('Updated description', $updatedMeta->description);

        // Update financial terms (price change: 15.00 EUR -> 20.00 EUR / 1500 -> 2000 minor units)
        $updatedPrice = $this->catalog()->updateFinancialTerms('safe.meta', 1, 2000, 'EUR', ['request.rejected' => 'automatic']);
        $this->assertSame(2, $updatedPrice->revision);
        $this->assertSame(2000, $updatedPrice->settings['billing_fixed_price_minor']);
        $this->assertSame('EUR', $updatedPrice->settings['billing_currency']);
        $this->assertSame('€20.00', sprintf('€%.2f', $updatedPrice->settings['billing_fixed_price_minor'] / 100));

        // Activate
        $active = $this->catalog()->activate('safe.meta', 2);
        $this->assertSame(3, $active->revision);
        $this->assertSame(ServiceStatus::Active, $active->status);
    }

    public function test_price_edit_creates_new_immutable_revision(): void
    {
        $active = $this->activate('price.test');
        $this->assertSame(1, $active->revision);
        $this->assertSame(15000, $active->settings['billing_fixed_price_minor']);
        $this->assertSame('€150.00', sprintf('€%.2f', $active->settings['billing_fixed_price_minor'] / 100));

        // Admin updates price to 18000 minor units (€180.00)
        $rev2 = $this->catalog()->updateFinancialTerms('price.test', 1, 18000, 'EUR', ['request.rejected' => 'automatic']);
        $this->assertSame(2, $rev2->revision);
        $this->assertSame(18000, $rev2->settings['billing_fixed_price_minor']);
        $this->assertSame('€180.00', sprintf('€%.2f', $rev2->settings['billing_fixed_price_minor'] / 100));

        // Physical database proof that catalog_services table holds revision 2 with 18000 minor units
        $dbRow = \Illuminate\Support\Facades\DB::table('catalog_services')->where('code', 'price.test')->first();
        $this->assertNotNull($dbRow);
        $this->assertSame(2, (int) $dbRow->revision);
        $storedSettings = json_decode((string) $dbRow->settings, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(18000, $storedSettings['billing_fixed_price_minor']);
        $this->assertSame('EUR', $storedSettings['billing_currency']);

        // Fetching service returns latest revision deterministically
        $fetched = $this->catalog()->get('price.test');
        $this->assertSame(2, $fetched->revision);
        $this->assertSame(18000, $fetched->settings['billing_fixed_price_minor']);
    }

    public function test_admin_mutations_emit_audit_evidence_for_all_lifecycle_events(): void
    {
        $this->audit->events = [];
        $created = $this->create('audit.svc');
        $this->assertNotEmpty($this->audit->events);
        $createEvent = $this->audit->events[count($this->audit->events) - 1];
        $this->assertSame('catalog.service.created', $createEvent->code);
        $this->assertSame('audit.svc', $createEvent->subjectId);

        $this->catalog()->updateMetadata('audit.svc', 0, 'New Name');
        $updateEvent = $this->audit->events[count($this->audit->events) - 1];
        $this->assertSame('catalog.service.updated', $updateEvent->code);
        $this->assertSame('audit.svc', $updateEvent->subjectId);

        $this->catalog()->activate('audit.svc', 1);
        $activateEvent = $this->audit->events[count($this->audit->events) - 1];
        $this->assertSame('catalog.service.activated', $activateEvent->code);
        $this->assertSame('audit.svc', $activateEvent->subjectId);

        $this->catalog()->deactivate('audit.svc', 2);
        $deactivateEvent = $this->audit->events[count($this->audit->events) - 1];
        $this->assertSame('catalog.service.deactivated', $deactivateEvent->code);
        $this->assertSame('audit.svc', $deactivateEvent->subjectId);

        $this->catalog()->archive('audit.svc', 3);
        $archiveEvent = $this->audit->events[count($this->audit->events) - 1];
        $this->assertSame('catalog.service.archived', $archiveEvent->code);
        $this->assertSame('audit.svc', $archiveEvent->subjectId);
    }

    public function test_update_workflow_binding_increments_revision_and_preserves_other_data(): void
    {
        $this->publish(1);
        $this->publish(2);

        $created = $this->catalog()->create($this->draft('wf.svc', 1, [
            'custom_key' => 'custom_val',
            'pricing_mode' => 'fixed_catalog',
            'billing_fixed_price_minor' => 1300,
            'billing_currency' => 'USD',
        ]));
        $this->assertSame(0, $created->revision);
        $this->assertSame(1, $created->workflow->version);

        $updated = $this->catalog()->updateWorkflowBinding('wf.svc', 0, $this->binding(2));
        $this->assertSame(1, $updated->revision);
        $this->assertSame(2, $updated->workflow->version);
        $this->assertSame('wf.svc', $updated->code);
        $this->assertSame($created->name, $updated->name);
        $this->assertSame($created->description, $updated->description);
        $this->assertSame('custom_val', $updated->settings['custom_key']);
        $this->assertSame(1300, $updated->settings['billing_fixed_price_minor']);
        $this->assertSame('USD', $updated->settings['billing_currency']);
        $this->assertSame(ServiceStatus::Inactive, $updated->status);

        // Immutable history check
        $rev0 = $this->catalog()->getRevision('wf.svc', 0);
        $this->assertSame(1, $rev0->workflow->version);
        $rev1 = $this->catalog()->getRevision('wf.svc', 1);
        $this->assertSame(2, $rev1->workflow->version);

        // Audit event emitted
        $updateEvent = $this->audit->events[count($this->audit->events) - 1];
        $this->assertSame('catalog.service.updated', $updateEvent->code);
        $this->assertSame('wf.svc', $updateEvent->subjectId);
    }

    public function test_update_workflow_binding_stale_revision_fails(): void
    {
        $this->publish(1);
        $this->publish(2);
        $this->catalog()->create($this->draft('wf.stale', 1));

        $this->catalog()->updateWorkflowBinding('wf.stale', 0, $this->binding(2));

        $this->expectException(StaleService::class);
        $this->catalog()->updateWorkflowBinding('wf.stale', 0, $this->binding(1));
    }

    public function test_update_workflow_binding_with_unregistered_definition_fails_closed(): void
    {
        $this->publish(1);
        $this->catalog()->create($this->draft('wf.fail', 1));

        $this->expectException(\RuntimeException::class);
        $this->catalog()->updateWorkflowBinding('wf.fail', 0, $this->binding(99));
    }
}

final class CatalogTestAccount extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class CatalogTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class CatalogTestScopes implements ScopeChecker
{
    public bool $throw = false;

    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($this->throw) {
            throw new \RuntimeException('Repository failure');
        }
        if ($request->resourceType === 'workflow.definition') {
            return $actor->role === 'platform_admin';
        }
        if ($request->resourceType === 'catalog.collection') {
            return true;
        }
        if ($request->resourceType === 'catalog.service') {
            if ($actor->role === 'agency') {
                // Example per-agency service entitlement is supplied by the host.
                return $actor->organizationId === 'agency-a' && $request->resourceId === 'sample';
            }
            return in_array($actor->role, ['government_admin', 'platform_admin'], true);
        }
        if ($request->resourceType === 'catalog.request') {
            return $actor->role === 'agency' && $actor->organizationId === 'agency-a'
                && in_array($request->resourceId, ['owned-1', 'owned-2'], true);
        }
        return false;
    }
}

final class CatalogTestAuditRecorder implements \Karband\Audit\AuditRecorderInterface
{
    /** @var list<\Karband\Audit\AuditEvent> */
    public array $events = [];

    public function record(\Karband\Audit\AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}
