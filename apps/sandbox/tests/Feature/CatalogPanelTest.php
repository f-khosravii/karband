<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bridges\WorkflowActorResolver;
use App\Bridges\WorkflowAuthorizationBridge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\CatalogPanel\Contracts\CatalogPanelPortInterface;
use Karband\CatalogPanel\Services\DefaultCatalogPanelAuthorization;
use Karband\CatalogPanel\Support\MoneyFormatter;
use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class CatalogPanelTest extends TestCase
{
    use RefreshDatabase;

    private PanelTestPrincipalProvider $principals;
    private PanelTestScopes $scopes;
    private PanelTestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create fine-grained permissions without requiring any role names
        foreach ([
            'catalog.service.create',
            'catalog.service.update',
            'catalog.service.price.update',
            'catalog.service.activate',
            'catalog.service.deactivate',
            'catalog.service.archive',
            'catalog.service.view',
            'catalog.service.list',
            'catalog.service.use',
            'workflow.definition.publish',
            'workflow.instance.start',
            'workflow.instance.view',
            'workflow.action.submit',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->user = PanelTestUser::create([
            'name' => 'Panel Test User',
            'email' => 'panel@example.test',
            'password' => 'secret',
        ]);

        $this->principals = new PanelTestPrincipalProvider();
        $this->scopes = new PanelTestScopes();
        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
    }

    private function setActorPermissions(array $permissions, string $roleName = 'test_actor_role'): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $this->user->syncRoles([$roleName]);
        Auth::guard('web')->setUser($this->user);
        $actor = new ActorContext((string) $this->user->getAuthIdentifier(), $roleName, 'local');
        $this->principals->principal = new Principal($this->user, $actor, 'web');
    }

    private function catalog(): ServiceCatalog
    {
        return $this->app->make(ServiceCatalog::class);
    }

    private function publishWorkflow(string $id, int $version): void
    {
        $this->app->make(DefinitionCatalog::class)->publish(new WorkflowDefinition(
            id: $id,
            version: $version,
            initialState: 'draft',
            states: ['draft', 'completed'],
            transitions: [
                new \Karband\Workflow\Definitions\Transition(
                    action: 'complete',
                    from: 'draft',
                    to: 'completed',
                    allowedRoles: ['actor'],
                ),
            ],
        ));
    }

    public function test_money_formatter_converts_minor_units_accurately_without_floats(): void
    {
        // 1300 EUR minor units -> €13.00
        self::assertSame('€13.00', MoneyFormatter::format(1300, 'EUR'));
        // 1500 USD minor units -> $15.00
        self::assertSame('$15.00', MoneyFormatter::format(1500, 'USD'));
        // 5000 GBP minor units -> £50.00
        self::assertSame('£50.00', MoneyFormatter::format(5000, 'GBP'));

        // Exact string arithmetic conversions (2 decimals)
        self::assertSame(1300, MoneyFormatter::toMinorUnits('13', 'EUR'));
        self::assertSame(1300, MoneyFormatter::toMinorUnits('13.0', 'EUR'));
        self::assertSame(1300, MoneyFormatter::toMinorUnits('13.00', 'EUR'));
        self::assertSame(1310, MoneyFormatter::toMinorUnits('13.10', 'EUR'));
        self::assertSame(1, MoneyFormatter::toMinorUnits('0.01', 'EUR'));
        self::assertSame(0, MoneyFormatter::toMinorUnits('0', 'EUR'));
        self::assertSame(0, MoneyFormatter::toMinorUnits('0.00', 'EUR'));

        // Conversion to HTML major input string
        self::assertSame('13.00', MoneyFormatter::toMajorString(1300, 'EUR'));
    }

    public function test_money_parsing_rejects_malformed_and_excess_precision_without_float_arithmetic(): void
    {
        // 1. Excess precision rejected rather than silently rounded
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Excess decimal precision');
        MoneyFormatter::toMinorUnits('13.001', 'EUR');
    }

    public function test_money_parsing_rejects_malformed_string_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed money amount');
        MoneyFormatter::toMinorUnits('abc', 'EUR');
    }

    public function test_money_parsing_rejects_negative_amounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed money amount');
        MoneyFormatter::toMinorUnits('-13.00', 'EUR');
    }

    public function test_money_parsing_handles_zero_and_three_decimal_currencies(): void
    {
        // 0-decimal currency (IRR / JPY)
        self::assertSame(1300, MoneyFormatter::toMinorUnits('1300', 'IRR'));
        self::assertSame(5000, MoneyFormatter::toMinorUnits('5000', 'JPY'));

        // 3-decimal currency (KWD / BHD)
        self::assertSame(13125, MoneyFormatter::toMinorUnits('13.125', 'KWD'));
        self::assertSame('13.125', MoneyFormatter::toMajorString(13125, 'KWD'));

        // Excess precision for 3-decimal
        try {
            MoneyFormatter::toMinorUnits('13.1255', 'KWD');
            $this->fail('Excess precision should fail for 3-decimal currencies.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Excess decimal precision', $e->getMessage());
        }
    }

    public function test_read_only_actor_can_view_list_and_detail_but_sees_no_mutation_controls(): void
    {
        // Setup service via admin capability
        $this->setActorPermissions(['catalog.service.create', 'catalog.service.view', 'catalog.service.list']);
        $this->catalog()->create(new ServiceDraft('alpha-service', 'Alpha Service', 'Alpha Description', []));

        // Switch actor to pure READ-ONLY capabilities with a completely arbitrary role name
        $this->setActorPermissions(['catalog.service.list', 'catalog.service.view'], 'read_only_auditor');

        // 1. List Page
        $response = $this->get('/catalog');
        $response->assertStatus(200);
        $response->assertSee('Alpha Service');
        $response->assertSee('alpha-service');
        $response->assertDontSee('+ Create Service');

        // 2. Detail Page
        $detail = $this->get('/catalog/alpha-service');
        $detail->assertStatus(200);
        $detail->assertSee('Alpha Service');
        $detail->assertSee('alpha-service');
        $detail->assertSee('Revision History');
        $detail->assertDontSee('Edit Metadata');
        $detail->assertDontSee('Configure Price');
        $detail->assertDontSee('Activate Service');
        $detail->assertDontSee('Deactivate Service');
    }

    public function test_unauthorized_actor_cannot_access_catalog_endpoints(): void
    {
        // First create a service
        $this->setActorPermissions(['catalog.service.create', 'catalog.service.view', 'catalog.service.list']);
        $this->catalog()->create(new ServiceDraft('alpha-service', 'Alpha Service', 'Alpha Description', []));

        // Switch to actor with NO catalog permissions
        $this->setActorPermissions([], 'unauthorized_guest');

        $this->get('/catalog')->assertStatus(403);
        $this->get('/catalog/create')->assertStatus(403);
        $this->post('/catalog', ['code' => 'hacked-service', 'name' => 'Hacked'])->assertStatus(403);
        $this->get('/catalog/alpha-service')->assertStatus(403);
        $this->get('/catalog/alpha-service/edit')->assertStatus(403);
        $this->put('/catalog/alpha-service', ['expected_revision' => 0, 'name' => 'Hacked'])->assertStatus(403);
        $this->get('/catalog/alpha-service/price')->assertStatus(403);
        $this->put('/catalog/alpha-service/price', ['expected_revision' => 0, 'price' => '10.00', 'currency' => 'EUR'])->assertStatus(403);
        $this->post('/catalog/alpha-service/activate', ['expected_revision' => 0])->assertStatus(403);
        $this->post('/catalog/alpha-service/deactivate', ['expected_revision' => 0])->assertStatus(403);
        $this->get('/catalog/alpha-service/history')->assertStatus(403);
    }

    public function test_metadata_update_permission_does_not_imply_price_edit_permission(): void
    {
        // Setup service
        $this->setActorPermissions(['catalog.service.create', 'catalog.service.view', 'catalog.service.list']);
        $this->catalog()->create(new ServiceDraft('split-perm-svc', 'Split Perm Service', 'Description', []));

        // Actor has view + update (metadata) but NOT price.update
        $this->setActorPermissions([
            'catalog.service.list',
            'catalog.service.view',
            'catalog.service.update',
        ], 'content_editor');

        // 1. Detail view shows "Edit Metadata" but NOT "Configure Price"
        $detail = $this->get('/catalog/split-perm-svc');
        $detail->assertStatus(200);
        $detail->assertSee('Edit Metadata');
        $detail->assertDontSee('Configure Price');

        // 2. Metadata edit page is accessible, metadata update succeeds
        $this->get('/catalog/split-perm-svc/edit')->assertStatus(200);
        $this->put('/catalog/split-perm-svc', [
            'expected_revision' => 0,
            'name' => 'Renamed Split Service',
            'description' => 'Updated Description',
        ])->assertRedirect('/catalog/split-perm-svc');

        // 3. Price edit page and price update are FORBIDDEN (403)
        $this->get('/catalog/split-perm-svc/price')->assertStatus(403);
        $this->put('/catalog/split-perm-svc/price', [
            'expected_revision' => 1,
            'price' => '20.00',
            'currency' => 'EUR',
            'pricing_mode' => 'fixed_catalog',
        ])->assertStatus(403);

        // 4. Now grant price.update capability to actor
        $this->setActorPermissions([
            'catalog.service.list',
            'catalog.service.view',
            'catalog.service.update',
            'catalog.service.price.update',
        ], 'financial_officer');

        // 5. Detail view now shows "Configure Price" and price mutation succeeds
        $detailWithPrice = $this->get('/catalog/split-perm-svc');
        $detailWithPrice->assertStatus(200);
        $detailWithPrice->assertSee('Configure Price');

        $this->get('/catalog/split-perm-svc/price')->assertStatus(200);
        $this->put('/catalog/split-perm-svc/price', [
            'expected_revision' => 1,
            'price' => '25.00',
            'currency' => 'EUR',
            'pricing_mode' => 'fixed_catalog',
        ])->assertRedirect('/catalog/split-perm-svc');

        $updated = $this->catalog()->get('split-perm-svc');
        self::assertSame(2500, $updated->settings['billing_fixed_price_minor']);
        self::assertSame(2, $updated->revision);
    }

    public function test_unconfigured_host_or_missing_authorization_fails_closed(): void
    {
        $defaultAuth = new DefaultCatalogPanelAuthorization();

        // With no gates registered, all permissions must evaluate to false (fail closed)
        self::assertFalse($defaultAuth->canList());
        self::assertFalse($defaultAuth->canView('any-code'));
        self::assertFalse($defaultAuth->canCreate());
        self::assertFalse($defaultAuth->canUpdate('any-code'));
        self::assertFalse($defaultAuth->canPrice('any-code'));
        self::assertFalse($defaultAuth->canActivate('any-code'));
        self::assertFalse($defaultAuth->canDeactivate('any-code'));
    }

    public function test_create_permission_exposes_create_form_and_produces_safe_inactive_service(): void
    {
        $this->setActorPermissions(['catalog.service.list', 'catalog.service.view', 'catalog.service.create']);

        // 1. Create form is accessible
        $this->get('/catalog/create')
            ->assertStatus(200)
            ->assertSee('Create Service')
            ->assertSee('Service Code');

        // 2. Create service via POST
        $response = $this->post('/catalog', [
            'code' => 'passport-service',
            'name' => 'Passport Issuance',
            'description' => 'Official passport renewal and issuance service',
        ]);

        $response->assertRedirect('/catalog/passport-service');
        $response->assertSessionHas('success');

        // 3. Confirm newly created service is in safe initial inactive state at Revision 0
        $created = $this->catalog()->get('passport-service');
        self::assertSame('passport-service', $created->code);
        self::assertSame('Passport Issuance', $created->name);
        self::assertSame('inactive', $created->status->value);
        self::assertSame(0, $created->revision);
    }

    public function test_update_permission_allows_metadata_editing_and_increments_revision(): void
    {
        $this->setActorPermissions(['catalog.service.list', 'catalog.service.view', 'catalog.service.create', 'catalog.service.update']);

        $this->catalog()->create(new ServiceDraft('license-service', 'Old License Name', 'Old Description', []));

        // 1. Edit form is accessible
        $this->get('/catalog/license-service/edit')
            ->assertStatus(200)
            ->assertSee('Old License Name')
            ->assertSee('Edit Service Metadata');

        // 2. Submit update via PUT
        $response = $this->put('/catalog/license-service', [
            'expected_revision' => 0,
            'name' => 'Renew Driving License',
            'description' => 'Updated official description for driver license renewal',
        ]);

        $response->assertRedirect('/catalog/license-service');

        // 3. Verify updated definition and revision advancement
        $updated = $this->catalog()->get('license-service');
        self::assertSame('Renew Driving License', $updated->name);
        self::assertSame('Updated official description for driver license renewal', $updated->description);
        self::assertSame(1, $updated->revision);
    }

    public function test_pricing_configuration_handles_minor_units_correctly_and_advances_revision(): void
    {
        $this->setActorPermissions([
            'catalog.service.list',
            'catalog.service.view',
            'catalog.service.create',
            'catalog.service.update',
            'catalog.service.price.update',
        ]);

        $this->catalog()->create(new ServiceDraft('consultation-service', 'Consultation', 'Legal Consultation', []));

        // 1. Price form is accessible
        $this->get('/catalog/consultation-service/price')
            ->assertStatus(200)
            ->assertSee('Configure Service Price');

        // 2. Submit price change (13.00 EUR major units entered by human)
        $response = $this->put('/catalog/consultation-service/price', [
            'expected_revision' => 0,
            'price' => '13.00',
            'currency' => 'EUR',
            'pricing_mode' => 'fixed_catalog',
        ]);

        $response->assertRedirect('/catalog/consultation-service');

        // 3. Verify storage has exact integer 1300 minor units, currency EUR, revision 1
        $updated = $this->catalog()->get('consultation-service');
        self::assertSame(1300, $updated->settings['billing_fixed_price_minor']);
        self::assertSame('EUR', $updated->settings['billing_currency']);
        self::assertSame(1, $updated->revision);

        // 4. Verify display on detail page formats 1300 minor units to €13.00
        $detail = $this->get('/catalog/consultation-service');
        $detail->assertStatus(200);
        $detail->assertSee('€13.00');
        $detail->assertSee('1300 minor units EUR');
    }

    public function test_activation_and_deactivation_lifecycle_actions(): void
    {
        $this->setActorPermissions([
            'catalog.service.list', 'catalog.service.view', 'catalog.service.create',
            'catalog.service.update', 'catalog.service.activate', 'catalog.service.deactivate',
            'workflow.definition.publish',
        ]);

        $this->publishWorkflow('wf-cert', 1);

        $this->catalog()->create(new ServiceDraft(
            code: 'cert-service',
            name: 'Certificate Issuance',
            description: 'Certificate Service',
            settings: [
                'pricing_mode' => 'fixed_catalog',
                'billing_fixed_price_minor' => 2500,
                'billing_currency' => 'EUR',
                'refund_events' => [
                    'workflow.cancelled' => 'automatic',
                ],
            ],
            workflow: new WorkflowBinding('wf-cert', 1),
        ));

        // 1. Service is initially inactive at Rev 0
        $initial = $this->catalog()->get('cert-service');
        self::assertSame('inactive', $initial->status->value);
        self::assertSame(0, $initial->revision);

        // 2. Activate service
        $actResponse = $this->post('/catalog/cert-service/activate', [
            'expected_revision' => 0,
        ]);
        $actResponse->assertRedirect('/catalog/cert-service');

        $active = $this->catalog()->get('cert-service');
        self::assertSame('active', $active->status->value);
        self::assertSame(1, $active->revision);

        // 3. Deactivate service
        $deactResponse = $this->post('/catalog/cert-service/deactivate', [
            'expected_revision' => 1,
        ]);
        $deactResponse->assertRedirect('/catalog/cert-service');

        $deactivated = $this->catalog()->get('cert-service');
        self::assertSame('inactive', $deactivated->status->value);
        self::assertSame(2, $deactivated->revision);
    }

    public function test_exact_historical_revisions_are_persisted_and_retrievable(): void
    {
        $this->setActorPermissions([
            'catalog.service.list', 'catalog.service.view', 'catalog.service.create',
            'catalog.service.update', 'catalog.service.price.update', 'catalog.service.activate',
            'workflow.definition.publish',
        ]);

        $this->publishWorkflow('wf-tax-return', 1);

        // Revision 0: Initial creation (no price, inactive)
        $this->post('/catalog', [
            'code' => 'tax-return-v1',
            'name' => 'Initial Tax Return',
            'description' => 'Original Draft Description',
        ]);

        // Revision 1: Update metadata
        $this->put('/catalog/tax-return-v1', [
            'expected_revision' => 0,
            'name' => 'Corporate Tax Return',
            'description' => 'Corporate Annual Tax Return Filing',
        ]);

        // Revision 2: Configure price to 13.00 EUR (1300 minor units)
        $this->put('/catalog/tax-return-v1/price', [
            'expected_revision' => 1,
            'price' => '13.00',
            'currency' => 'EUR',
            'pricing_mode' => 'fixed_catalog',
        ]);

        // Revision 3: Bind workflow and update metadata with refund_events
        $this->catalog()->replace('tax-return-v1', 2, new ServiceDraft(
            code: 'tax-return-v1',
            name: 'Corporate Tax Return Published',
            description: 'Corporate Annual Tax Return Filing',
            settings: [
                'pricing_mode' => 'fixed_catalog',
                'billing_fixed_price_minor' => 1300,
                'billing_currency' => 'EUR',
                'refund_events' => [
                    'workflow.cancelled' => 'automatic',
                ],
            ],
            workflow: new WorkflowBinding('wf-tax-return', 1),
        ));

        // Revision 4: Activate
        $this->post('/catalog/tax-return-v1/activate', [
            'expected_revision' => 3,
        ]);

        // Revision 5: Change price to 15.00 EUR (1500 minor units)
        $this->put('/catalog/tax-return-v1/price', [
            'expected_revision' => 4,
            'price' => '15.00',
            'currency' => 'EUR',
            'pricing_mode' => 'fixed_catalog',
        ]);

        // Live current version is Revision 5 with 1500 minor units (15.00 EUR)
        $current = $this->catalog()->get('tax-return-v1');
        self::assertSame(5, $current->revision);
        self::assertSame(1500, $current->settings['billing_fixed_price_minor']);

        // Check Revision History index page
        $historyPage = $this->get('/catalog/tax-return-v1/history');
        $historyPage->assertStatus(200);
        $historyPage->assertSee('Rev 5');
        $historyPage->assertSee('Rev 2');
        $historyPage->assertSee('Rev 0');

        // Inspect exact historical Revision 0
        $rev0 = $this->get('/catalog/tax-return-v1/history/0');
        $rev0->assertStatus(200);
        $rev0->assertSee('Initial Tax Return');
        $rev0->assertSee('Original Draft Description');
        $rev0->assertSee('Revision 0');

        // Inspect exact historical Revision 2 (price was 13.00 EUR / 1300 minor units)
        $rev2 = $this->get('/catalog/tax-return-v1/history/2');
        $rev2->assertStatus(200);
        $rev2->assertSee('Corporate Tax Return');
        $rev2->assertSee('€13.00');
        $rev2->assertSee('1300 minor units EUR');
        $rev2->assertSee('Revision 2');
        $rev2->assertDontSee('€15.00'); // Must NOT show current price for historical revision 2!

        // Inspect live Revision 5
        $rev5 = $this->get('/catalog/tax-return-v1/history/5');
        $rev5->assertStatus(200);
        $rev5->assertSee('€15.00');
        $rev5->assertSee('1500 minor units EUR');
        $rev5->assertSee('Current Live Version');

        // Direct ServiceCatalog::getRevision API verification
        $persistedRev0 = $this->catalog()->getRevision('tax-return-v1', 0);
        self::assertSame(0, $persistedRev0->revision);
        self::assertSame('Initial Tax Return', $persistedRev0->name);
        self::assertArrayNotHasKey('billing_fixed_price_minor', $persistedRev0->settings);

        $persistedRev2 = $this->catalog()->getRevision('tax-return-v1', 2);
        self::assertSame(2, $persistedRev2->revision);
        self::assertSame(1300, $persistedRev2->settings['billing_fixed_price_minor']);
        self::assertSame('EUR', $persistedRev2->settings['billing_currency']);

        $persistedRev5 = $this->catalog()->getRevision('tax-return-v1', 5);
        self::assertSame(5, $persistedRev5->revision);
        self::assertSame(1500, $persistedRev5->settings['billing_fixed_price_minor']);
    }

    public function test_optimistic_concurrency_failure_with_stale_revision(): void
    {
        $this->setActorPermissions(['catalog.service.list', 'catalog.service.view', 'catalog.service.create', 'catalog.service.update']);

        $this->catalog()->create(new ServiceDraft('stale-check', 'Stale Check', null, []));

        // Try to update with wrong expected_revision (e.g. expected 99 instead of 0)
        $this->expectException(\Karband\ServiceCatalogLaravel\Exceptions\StaleService::class);
        $this->catalog()->updateMetadata('stale-check', 99, 'New Name');
    }

    public function test_deactivated_service_remains_visible_in_panel_history_but_unavailable_for_use(): void
    {
        $this->setActorPermissions([
            'catalog.service.list', 'catalog.service.view', 'catalog.service.create',
            'catalog.service.update', 'catalog.service.activate', 'catalog.service.deactivate',
            'catalog.service.use', 'workflow.definition.publish',
        ]);

        $this->publishWorkflow('wf-deact-test', 1);

        // Create & Activate
        $this->catalog()->create(new ServiceDraft(
            code: 'service-to-deactivate',
            name: 'Service To Deactivate',
            description: 'Testing deactivation lifecycle',
            settings: [
                'pricing_mode' => 'fixed_catalog',
                'billing_fixed_price_minor' => 1000,
                'billing_currency' => 'EUR',
                'refund_events' => ['workflow.cancelled' => 'automatic'],
            ],
            workflow: new WorkflowBinding('wf-deact-test', 1),
        ));
        $this->catalog()->activate('service-to-deactivate', 0);

        // While active, requireForUse succeeds
        $activeDef = $this->catalog()->requireForUse('service-to-deactivate');
        self::assertSame('active', $activeDef->status->value);

        // Now deactivate via panel endpoint
        $this->post('/catalog/service-to-deactivate/deactivate', ['expected_revision' => 1])
            ->assertRedirect('/catalog/service-to-deactivate');

        // Verify it remains visible in panel list with inactive badge
        $this->get('/catalog')
            ->assertStatus(200)
            ->assertSee('service-to-deactivate')
            ->assertSee('inactive');

        // Verify it remains visible in history
        $this->get('/catalog/service-to-deactivate/history')
            ->assertStatus(200)
            ->assertSee('Rev 2')
            ->assertSee('inactive');

        // But requireForUse for new requests MUST fail closed with ServiceUnavailable
        $this->expectException(\Karband\ServiceCatalogLaravel\Exceptions\ServiceUnavailable::class);
        $this->catalog()->requireForUse('service-to-deactivate');
    }

    public function test_list_search_and_filter_by_status(): void
    {
        $this->setActorPermissions([
            'catalog.service.list', 'catalog.service.view', 'catalog.service.create',
            'catalog.service.update', 'catalog.service.activate',
            'workflow.definition.publish',
        ]);

        $this->publishWorkflow('wf-filter', 1);

        $this->catalog()->create(new ServiceDraft('alpha-search', 'Alpha Document', 'Desc', []));
        $this->catalog()->create(new ServiceDraft(
            code: 'beta-search',
            name: 'Beta Legal Form',
            description: 'Desc',
            settings: [
                'pricing_mode' => 'fixed_catalog',
                'billing_fixed_price_minor' => 2000,
                'billing_currency' => 'USD',
                'refund_events' => [],
            ],
            workflow: new WorkflowBinding('wf-filter', 1),
        ));
        $this->catalog()->activate('beta-search', 0);

        // Search by keyword
        $this->get('/catalog?q=Document')
            ->assertStatus(200)
            ->assertSee('Alpha Document')
            ->assertDontSee('Beta Legal Form');

        // Filter by active status
        $this->get('/catalog?status=active')
            ->assertStatus(200)
            ->assertSee('Beta Legal Form')
            ->assertDontSee('Alpha Document');

        // Filter by inactive status
        $this->get('/catalog?status=inactive')
            ->assertStatus(200)
            ->assertSee('Alpha Document')
            ->assertDontSee('Beta Legal Form');
    }

    public function test_non_eur_currencies_format_and_persist_correctly(): void
    {
        $this->setActorPermissions([
            'catalog.service.list',
            'catalog.service.view',
            'catalog.service.create',
            'catalog.service.update',
            'catalog.service.price.update',
        ]);

        $this->catalog()->create(new ServiceDraft('usd-service', 'USD Service', null, []));

        // Configure USD price: 99.50 USD entered -> 9950 minor units
        $this->put('/catalog/usd-service/price', [
            'expected_revision' => 0,
            'price' => '99.50',
            'currency' => 'USD',
            'pricing_mode' => 'fixed_catalog',
        ])->assertRedirect('/catalog/usd-service');

        $detail = $this->get('/catalog/usd-service');
        $detail->assertStatus(200);
        $detail->assertSee('$99.50');
        $detail->assertSee('9950 minor units USD');

        $stored = $this->catalog()->get('usd-service');
        self::assertSame(9950, $stored->settings['billing_fixed_price_minor']);
        self::assertSame('USD', $stored->settings['billing_currency']);
    }
}

final class PanelTestUser extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class PanelTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class PanelTestScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        // Pure permission/capability checking via Spatie permissions on authenticated user
        $user = Auth::guard('web')->user();
        if (!$user instanceof BaseUser) {
            return false;
        }

        return $user->hasPermissionTo($request->ability, 'web');
    }
}
