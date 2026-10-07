<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

final class CatalogMigrationUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private UpgradeTestPrincipalProvider $principals;
    private UpgradeTestScopes $scopes;
    private UpgradeTestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'catalog.service.create',
            'catalog.service.update',
            'catalog.service.price.update',
            'catalog.service.activate',
            'catalog.service.deactivate',
            'catalog.service.view',
            'catalog.service.list',
            'workflow.definition.publish',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $role = Role::findOrCreate('admin_role', 'web');
        $role->syncPermissions([
            'catalog.service.create',
            'catalog.service.update',
            'catalog.service.price.update',
            'catalog.service.activate',
            'catalog.service.deactivate',
            'catalog.service.view',
            'catalog.service.list',
            'workflow.definition.publish',
        ]);

        $this->user = UpgradeTestUser::create([
            'name' => 'Upgrade Test User',
            'email' => 'upgrade@example.test',
            'password' => 'secret',
        ]);
        $this->user->assignRole('admin_role');

        Auth::guard('web')->setUser($this->user);
        $this->principals = new UpgradeTestPrincipalProvider();
        $actor = new ActorContext((string) $this->user->getAuthIdentifier(), 'admin_role', 'local');
        $this->principals->principal = new Principal($this->user, $actor, 'web');
        $this->scopes = new UpgradeTestScopes();

        $this->app->instance(PrincipalProvider::class, $this->principals);
        $this->app->instance(ScopeChecker::class, $this->scopes);
    }

    public function test_upgrade_from_pre_history_schema_creates_truthful_baseline_snapshot_without_fake_revisions(): void
    {
        // 1. Simulate an existing pre-history service row in catalog_services that has no revision snapshots in catalog_service_revisions:
        DB::table('catalog_services')->insert([
            'code' => 'legacy.tax.service',
            'name' => 'Legacy Tax Filing',
            'description' => 'Pre-existing service before revision history was introduced',
            'settings' => json_encode([
                'pricing_mode' => 'fixed_catalog',
                'billing_fixed_price_minor' => 1300,
                'billing_currency' => 'EUR',
                'refund_events' => ['workflow.rejected' => 'automatic'],
            ]),
            'status' => 'active',
            'workflow_id' => 'wf-legacy',
            'workflow_version' => 1,
            'revision' => 7,
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(5),
        ]);

        // Ensure no prior revision rows exist for this legacy service
        DB::table('catalog_service_revisions')->where('service_code', 'legacy.tax.service')->delete();
        self::assertDatabaseMissing('catalog_service_revisions', ['service_code' => 'legacy.tax.service']);

        // 2. Run the forward-only migration upgrade logic
        $migrationFile = base_path('../../packages/service-catalog/adapter/database/migrations/2024_01_02_000000_create_catalog_service_revisions_table.php');
        $migration = require $migrationFile;
        $migration->up();

        // 3. Verify that EXACTLY ONE baseline snapshot exists for revision 7
        $revisions = DB::table('catalog_service_revisions')
            ->where('service_code', 'legacy.tax.service')
            ->get();

        self::assertCount(1, $revisions);
        self::assertSame(7, (int) $revisions[0]->revision);
        self::assertSame('Legacy Tax Filing', $revisions[0]->name);
        self::assertSame('active', $revisions[0]->status);

        // 4. Verify NO synthesized fake revisions (1..6) exist
        $fakeRevisions = DB::table('catalog_service_revisions')
            ->where('service_code', 'legacy.tax.service')
            ->whereIn('revision', [1, 2, 3, 4, 5, 6])
            ->get();
        self::assertCount(0, $fakeRevisions);

        // 5. Verify ServiceCatalog can read the upgraded service and its baseline revision
        $catalog = $this->app->make(ServiceCatalog::class);
        $service = $catalog->get('legacy.tax.service');
        self::assertSame('legacy.tax.service', $service->code);
        self::assertSame(7, $service->revision);
        self::assertSame(1300, $service->settings['billing_fixed_price_minor']);

        $rev7 = $catalog->getRevision('legacy.tax.service', 7);
        self::assertSame(7, $rev7->revision);
        self::assertSame('Legacy Tax Filing', $rev7->name);

        $this->app->make(DefinitionCatalog::class)->publish(new WorkflowDefinition(
            id: 'wf-legacy',
            version: 1,
            initialState: 'draft',
            states: ['draft', 'completed'],
            transitions: [],
        ));

        // 6. Verify subsequent mutation creates next revision 8 in catalog_service_revisions
        $rev8 = $catalog->updateMetadata('legacy.tax.service', 7, 'Updated Legacy Tax Filing', 'New Description');
        self::assertSame(8, $rev8->revision);

        $rev8FromDb = $catalog->getRevision('legacy.tax.service', 8);
        self::assertSame(8, $rev8FromDb->revision);
        self::assertSame('Updated Legacy Tax Filing', $rev8FromDb->name);

        // History now has exactly 2 records: revision 8 and revision 7
        $history = $catalog->history('legacy.tax.service');
        self::assertCount(2, $history);
        self::assertSame(8, $history[0]->revision);
        self::assertSame(7, $history[1]->revision);
    }
}

final class UpgradeTestUser extends BaseUser
{
    use HasRoles;

    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class UpgradeTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class UpgradeTestScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        return true;
    }
}
