<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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
use Intl\DocumentsLaravel\Exceptions\UploadRejected;
use Intl\DocumentsLaravel\Services\DocumentManager;
use Intl\DocumentsLaravel\Services\DocumentRegistry;
use Intl\DocumentsLaravel\Services\TrustedDocumentFacts;
use Intl\ServiceCatalog\Definitions\ServiceDraft;
use Intl\ServiceCatalog\Definitions\WorkflowBinding;
use Intl\ServiceCatalogLaravel\Services\ServiceCatalog;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\WorkflowLaravel\Contracts\ActorResolver;
use Intl\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Intl\WorkflowLaravel\Services\DefinitionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

/** Integration against the current independent INTL packages and an isolated SQLite test DB. */
final class IntlDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private DocumentsTestUser $user;
    private DocumentsTestPrincipalProvider $principal;
    private DocumentsTestScopes $scopes;
    private DocumentsTestResources $resources;
    private DocumentsTestReplacementPolicy $policy;
    private DocumentsFixtureScanner $scanner;
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('intl-documents.private_disk', 'local');
        config()->set('intl-documents.absolute_max_bytes', 10_485_760);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency', 'government_admin', 'platform_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        foreach ([
            'workflow.definition.publish', 'catalog.service.create', 'catalog.service.activate',
            'catalog.service.deactivate', 'catalog.service.view', 'catalog.service.use',
            'documents.definition.edit', 'documents.definition.view', 'documents.definition.publish',
            'documents.file.upload', 'documents.file.view', 'documents.file.replace', 'documents.file.withdraw',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }
        Role::findByName('platform_admin', 'web')->givePermissionTo([
            'workflow.definition.publish', 'catalog.service.create', 'catalog.service.activate',
            'catalog.service.deactivate', 'catalog.service.view', 'documents.definition.edit',
            'documents.definition.view', 'documents.definition.publish',
        ]);
        Role::findByName('agency', 'web')->givePermissionTo([
            'catalog.service.use', 'documents.file.upload', 'documents.file.view', 'documents.file.replace', 'documents.file.withdraw',
        ]);
        Role::findByName('government_admin', 'web')->givePermissionTo([
            'documents.definition.view', 'documents.file.view',
        ]);
        $this->user = DocumentsTestUser::create([
            'name' => 'Document tester', 'email' => 'doc@test.example', 'password' => 'unused',
        ]);
        $this->principal = new DocumentsTestPrincipalProvider();
        $this->scopes = new DocumentsTestScopes();
        $this->resources = new DocumentsTestResources();
        $this->policy = new DocumentsTestReplacementPolicy();
        $this->scanner = new DocumentsFixtureScanner();
        $this->app->instance(PrincipalProvider::class, $this->principal);
        $this->app->instance(ScopeChecker::class, $this->scopes);
        $this->app->bind(ActorResolver::class, WorkflowActorResolver::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->app->instance(ResourceVerifier::class, $this->resources);
        $this->app->instance(ReplacementPolicy::class, $this->policy);
        $this->app->instance(FileSafetyScanner::class, $this->scanner);
        $this->asRole('platform_admin', 'platform');
        $this->app->make(DefinitionCatalog::class)->publish(WorkflowDefinition::fromArray([
            'id' => 'benefit.workflow', 'version' => 1, 'initial_state' => 'draft',
            'states' => ['draft', 'correction_required'],
            'transitions' => [['action' => 'correction', 'from' => 'draft',
                'to' => 'correction_required', 'allowed_roles' => ['government_admin']]],
        ]));
        $catalog = $this->app->make(ServiceCatalog::class);
        $entry = $catalog->create(new ServiceDraft('benefit', 'Benefit', null, [], new WorkflowBinding('benefit.workflow', 1)));
        $catalog->activate('benefit', $entry->revision);
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function asRole(string $role, ?string $organization): void
    {
        $this->user->syncRoles([$role]);
        Auth::guard('web')->setUser($this->user);
        $this->principal->principal = new Principal($this->user,
            new ActorContext((string) $this->user->getAuthIdentifier(), $role, $organization), 'web');
    }

    private function definition(int $version = 1, bool $extra = false): DocumentDefinition
    {
        $slots = [new DocumentRequirement('passport', 'Passport', true, ['application/pdf'], 3000),
            new DocumentRequirement('note', 'Optional note', false, ['application/pdf'], 3000)];
        if ($extra) {
            $slots[] = new DocumentRequirement('photo', 'Photograph', true, ['application/pdf'], 3000);
        }
        return new DocumentDefinition('benefit', $version, $slots);
    }

    private function publish(int $version = 1, bool $extra = false): void
    {
        $this->asRole('platform_admin', 'platform');
        $this->registry()->publishDraft('benefit', $this->registry()->saveDraft($this->definition($version, $extra)));
    }

    private function registry(): DocumentRegistry
    {
        return $this->app->make(DocumentRegistry::class);
    }

    private function docs(): DocumentManager
    {
        return $this->app->make(DocumentManager::class);
    }

    private function pdf(string $name = 'passport.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'intl_document_');
        $this->temps[] = $path;
        file_put_contents($path, "%PDF-1.5\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }

    private function reserve(string $id = 'owned-1'): array
    {
        $this->asRole('agency', 'agency-a');
        return $this->docs()->reserve('benefit', 'doc.request', $id);
    }

    public function test_admin_can_publish_immutable_requirements(): void
    {
        $this->publish();
        self::assertSame($this->definition()->toArray(), $this->registry()->published('benefit', 1)->toArray());
        self::assertDatabaseCount('intl_document_definitions', 1);
        self::assertDatabaseCount('intl_document_drafts', 0);
    }

    public function test_agency_cannot_edit_definitions(): void
    {
        $this->asRole('agency', 'agency-a');
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->saveDraft($this->definition());
    }

    public function test_government_can_view_but_cannot_publish(): void
    {
        $this->publish();
        $this->asRole('government_admin', 'government');
        self::assertSame(1, $this->registry()->published('benefit', 1)->version);
        $this->expectException(AuthorizationDenied::class);
        $this->registry()->publishDraft('benefit', 0);
    }

    public function test_rejects_unsafe_administrator_mime(): void
    {
        $this->expectException(DocumentConflict::class);
        $this->registry()->saveDraft(new DocumentDefinition('benefit', 1, [
            new DocumentRequirement('script', 'Script', false, ['text/html']),
        ]));
    }

    public function test_stale_draft_cannot_override_newer_edit(): void
    {
        $this->registry()->saveDraft($this->definition());
        self::assertSame(1, $this->registry()->saveDraft($this->definition(), 0));
        $this->expectException(DocumentConflict::class);
        $this->registry()->saveDraft($this->definition(), 0);
    }

    public function test_cannot_publish_same_version_twice(): void
    {
        $this->publish();
        $this->expectException(DocumentConflict::class);
        $this->registry()->saveDraft($this->definition());
    }

    public function test_reservation_pins_definition_and_starts_empty(): void
    {
        $this->publish();
        self::assertSame(['version' => 1, 'revision' => 0], $this->reserve());
        $data = $this->docs()->snapshot('benefit', 'doc.request', 'owned-1');
        self::assertSame(['passport'], $data['missing']);
        self::assertSame([], $data['files']);
    }

    public function test_unknown_resource_cannot_be_reserved(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-a');
        $this->expectException(AuthorizationDenied::class);
        $this->docs()->reserve('benefit', 'doc.request', 'made-up');
    }

    public function test_foreign_agency_cannot_reserve_others_resource(): void
    {
        $this->publish();
        $this->asRole('agency', 'agency-b');
        $this->expectException(AuthorizationDenied::class);
        $this->docs()->reserve('benefit', 'doc.request', 'owned-1');
    }

    public function test_duplicate_resource_cannot_be_reserved(): void
    {
        $this->publish();
        $this->reserve();
        $this->expectException(DocumentConflict::class);
        $this->docs()->reserve('benefit', 'doc.request', 'owned-1');
    }

    public function test_upload_stores_private_file_and_completes_required(): void
    {
        $this->publish();
        $this->reserve();
        $result = $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf('..\\secret.pdf'), 0);
        self::assertSame(1, $result['set_revision']);
        self::assertSame('application/pdf', $result['mime_type']);
        $data = $this->docs()->snapshot('benefit', 'doc.request', 'owned-1');
        self::assertSame([], $data['missing']);
        self::assertSame(1, count($data['files']));
        self::assertArrayNotHasKey('storage_key', $data['files'][0]);
        self::assertSame('secret.pdf', $data['files'][0]['name']);
        self::assertTrue($this->app->make(TrustedDocumentFacts::class)->isComplete('benefit', 'doc.request', 'owned-1'));
        self::assertCount(1, Storage::disk('local')->allFiles('intl-documents'));
        $stream = $this->docs()->open('benefit', 'doc.request', 'owned-1', $result['id']);
        self::assertStringStartsWith('%PDF', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_optional_document_is_not_required(): void
    {
        $this->publish();
        $this->reserve();
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'note', $this->pdf(), 0);
        self::assertSame(['passport'], $this->docs()->snapshot('benefit', 'doc.request', 'owned-1')['missing']);
    }

    public function test_unknown_slot_does_not_write_file(): void
    {
        $this->publish();
        $this->reserve();
        $this->expectException(UploadRejected::class);
        try {
            $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'fraud', $this->pdf(), 0);
        } finally {
            self::assertSame([], Storage::disk('local')->allFiles('intl-documents'));
        }
    }

    public function test_file_size_rejected_without_mutation(): void
    {
        $this->publish();
        $this->reserve();
        $file = $this->pdf();
        file_put_contents($file->getRealPath(), str_repeat('A', 4000), FILE_APPEND);
        $this->expectException(UploadRejected::class);
        try {
            $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $file, 0);
        } finally {
            self::assertDatabaseCount('intl_document_files', 0);
        }
    }

    public function test_mime_spoof_is_rejected(): void
    {
        $this->publish();
        $this->reserve();
        $path = tempnam(sys_get_temp_dir(), 'fake_doc_');
        $this->temps[] = $path;
        file_put_contents($path, '<html>unsafe</html>');
        $this->expectException(UploadRejected::class);
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport',
            new UploadedFile($path, 'passport.pdf', 'application/pdf', UPLOAD_ERR_OK, true), 0);
    }

    public function test_scanner_rejection_prevents_file_write(): void
    {
        $this->publish();
        $this->reserve();
        $this->scanner->reject = true;
        $this->expectException(UploadRejected::class);
        try {
            $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 0);
        } finally {
            self::assertDatabaseCount('intl_document_files', 0);
            self::assertSame([], Storage::disk('local')->allFiles('intl-documents'));
        }
    }

    public function test_cannot_replace_without_trusted_correction_state(): void
    {
        $this->publish();
        $this->reserve();
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 0);
        $this->expectException(AuthorizationDenied::class);
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 1);
    }

    public function test_correction_replacement_appends_audit_history(): void
    {
        $this->publish();
        $this->reserve();
        $old = $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 0);
        $this->policy->state = 'correction_required';
        $new = $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf('corrected.pdf'), 1);
        self::assertSame(2, $new['slot_version']);
        $history = $this->docs()->history('benefit', 'doc.request', 'owned-1');
        self::assertCount(2, $history);
        self::assertSame($old['id'], $history[1]['supersedes_id']);
        self::assertSame($new['id'], $this->docs()->snapshot('benefit', 'doc.request', 'owned-1')['files'][0]['id']);
        self::assertCount(2, Storage::disk('local')->allFiles('intl-documents'));
        $stream = $this->docs()->open('benefit', 'doc.request', 'owned-1', $old['id']);
        self::assertStringStartsWith('%PDF', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_stale_revision_does_not_write_file(): void
    {
        $this->publish();
        $this->reserve();
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'note', $this->pdf(), 0);
        $this->expectException(DocumentConflict::class);
        try {
            $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 0);
        } finally {
            self::assertDatabaseCount('intl_document_files', 1);
            self::assertCount(1, Storage::disk('local')->allFiles('intl-documents'));
        }
    }

    public function test_old_set_pinned_when_new_document_requirements_published(): void
    {
        $this->publish();
        $this->reserve();
        $this->publish(2, true);
        $this->asRole('agency', 'agency-a');
        self::assertSame(1, $this->docs()->snapshot('benefit', 'doc.request', 'owned-1')['version']);
        self::assertSame(2, $this->docs()->reserve('benefit', 'doc.request', 'owned-2')['version']);
    }

    public function test_deactivation_blocks_new_set_but_existing_can_finish(): void
    {
        $this->publish();
        $this->reserve();
        $this->asRole('platform_admin', 'platform');
        $catalog = $this->app->make(ServiceCatalog::class);
        $service = $catalog->get('benefit');
        $catalog->deactivate('benefit', $service->revision);
        $this->asRole('agency', 'agency-a');
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 0);
        $this->expectException(DocumentNotFound::class);
        $this->docs()->reserve('benefit', 'doc.request', 'owned-2');
    }

    public function test_government_can_view_but_cannot_upload(): void
    {
        $this->publish();
        $this->reserve();
        $this->asRole('government_admin', 'government');
        self::assertSame(['passport'], $this->docs()->snapshot('benefit', 'doc.request', 'owned-1')['missing']);
        $this->expectException(AuthorizationDenied::class);
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 0);
    }

    public function test_other_agency_cannot_view_or_download(): void
    {
        $this->publish();
        $this->reserve();
        $old = $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 0);
        $this->asRole('agency', 'agency-b');
        $this->expectException(AuthorizationDenied::class);
        $this->docs()->open('benefit', 'doc.request', 'owned-1', $old['id']);
    }

    public function test_missing_principal_fails_closed(): void
    {
        $this->publish();
        $this->principal->principal = null;
        $this->expectException(\RuntimeException::class);
        $this->registry()->draft('benefit');
    }

    public function test_scope_exception_fails_closed(): void
    {
        $this->publish();
        $this->reserve();
        $this->scopes->throw = true;
        $this->expectException(AuthorizationDenied::class);
        $this->docs()->snapshot('benefit', 'doc.request', 'owned-1');
    }

    public function test_missing_resource_binding_is_closed(): void
    {
        $this->publish();
        $this->app->instance(ResourceVerifier::class, new class implements ResourceVerifier {
            public function existsForService(string $serviceCode, string $resourceType, string $resourceId): bool
            {
                throw new \RuntimeException('Resource database unavailable');
            }
        });
        $this->asRole('agency', 'agency-a');
        $this->expectException(AuthorizationDenied::class);
        $this->docs()->reserve('benefit', 'doc.request', 'owned-1');
    }

    public function test_public_disk_configuration_is_rejected(): void
    {
        $this->publish();
        $this->reserve();
        config()->set('intl-documents.private_disk', 'public');
        $this->expectException(\LogicException::class);
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf(), 0);
    }

    public function test_trusted_fact_false_for_nonreserved_set(): void
    {
        $this->publish();
        self::assertFalse($this->app->make(TrustedDocumentFacts::class)->isComplete('benefit', 'doc.request', 'owned-1'));
    }

    public function test_published_definition_tampering_detected(): void
    {
        $this->publish();
        DB::table('intl_document_definitions')->where('service_code', 'benefit')->where('version', 1)
            ->update(['definition' => json_encode($this->definition(2)->toArray(), JSON_THROW_ON_ERROR)]);
        $this->expectException(\UnexpectedValueException::class);
        $this->registry()->published('benefit', 1);
    }

    public function test_logical_withdrawal_marks_file_withdrawn_and_increments_revision(): void
    {
        $this->publish();
        $this->reserve('owned-1');
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf('passport.pdf'), 0);

        $withdrawn = $this->docs()->withdraw('benefit', 'doc.request', 'owned-1', 'passport', 'Customer requested withdrawal', 1);

        self::assertTrue($withdrawn['is_withdrawn']);
        self::assertSame('Customer requested withdrawal', $withdrawn['withdrawal_reason']);
        self::assertNotNull($withdrawn['withdrawn_at']);
        self::assertSame((string) $this->user->getAuthIdentifier(), $withdrawn['withdrawn_by']);

        // Set revision incremented to 2
        $snap = $this->docs()->snapshot('benefit', 'doc.request', 'owned-1');
        self::assertSame(2, $snap['revision']);
        self::assertEmpty($snap['files']); // Active files = none!
        self::assertContains('passport', $snap['missing']);
    }

    public function test_slot_can_be_reuploaded_after_withdrawal(): void
    {
        $this->publish();
        $this->reserve('owned-1');
        $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf('passport1.pdf'), 0);
        $this->docs()->withdraw('benefit', 'doc.request', 'owned-1', 'passport', 'Outdated', 1);

        // Upload new active file into withdrawn slot
        $newUpload = $this->docs()->upload('benefit', 'doc.request', 'owned-1', 'passport', $this->pdf('passport2.pdf'), 2);

        self::assertSame(2, $newUpload['slot_version']);
        self::assertSame(3, $newUpload['set_revision']);

        $snap = $this->docs()->snapshot('benefit', 'doc.request', 'owned-1');
        self::assertCount(1, $snap['files']);
        self::assertFalse($snap['files'][0]['is_withdrawn']);
        self::assertSame('passport2.pdf', $snap['files'][0]['name']);
        self::assertNotContains('passport', $snap['missing']);

        // History contains both files (withdrawn and active)
        $history = $this->docs()->history('benefit', 'doc.request', 'owned-1');
        self::assertCount(2, $history);
        self::assertTrue($history[0]['is_withdrawn']);
        self::assertFalse($history[1]['is_withdrawn']);
    }

    public function test_withdrawing_empty_slot_fails(): void
    {
        $this->publish();
        $this->reserve('owned-1');

        $this->expectException(DocumentNotFound::class);
        $this->docs()->withdraw('benefit', 'doc.request', 'owned-1', 'passport', 'No file', 0);
    }

    public function test_snapshot_evaluates_required_when_and_required_at_facts(): void
    {
        $this->asRole('platform_admin', 'platform');
        $v2Def = new DocumentDefinition('benefit', 1, [
            new DocumentRequirement('poa', 'Power of Attorney', false, ['application/pdf'], 3000, ['field' => 'has_rep', 'equals' => true]),
            new DocumentRequirement('cert', 'Final Cert', false, ['application/pdf'], 3000, null, ['completion']),
        ]);
        $this->registry()->publishDraft('benefit', $this->registry()->saveDraft($v2Def));

        $this->asRole('agency', 'agency-a');
        $this->docs()->reserve('benefit', 'doc.request', 'owned-2');

        // 1. Facts: has_rep = false, checkpoint = draft -> none required
        $snap1 = $this->docs()->snapshot('benefit', 'doc.request', 'owned-2', ['has_rep' => false], 'draft');
        self::assertSame([], $snap1['missing']);

        // 2. Facts: has_rep = true -> poa is required
        $snap2 = $this->docs()->snapshot('benefit', 'doc.request', 'owned-2', ['has_rep' => true], 'draft');
        self::assertSame(['poa'], $snap2['missing']);

        // 3. Checkpoint: completion -> cert is required
        $snap3 = $this->docs()->snapshot('benefit', 'doc.request', 'owned-2', ['has_rep' => false], 'completion');
        self::assertSame(['cert'], $snap3['missing']);
    }
}

final class DocumentsTestUser extends BaseUser
{
    use HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web';
    protected $guarded = [];
}

final class DocumentsTestPrincipalProvider implements PrincipalProvider
{
    public ?Principal $principal = null;

    public function current(): Principal
    {
        return $this->principal ?? throw new \RuntimeException('Not authenticated');
    }
}

final class DocumentsTestScopes implements ScopeChecker
{
    public bool $throw = false;

    public function allows(ActorContext $actor, AccessRequest $request): bool
    {
        if ($this->throw) {
            throw new \RuntimeException('Scope unavailable');
        }
        if ($request->resourceType === 'workflow.definition') {
            return $actor->role === 'platform_admin';
        }
        if ($request->resourceType === 'catalog.service') {
            return $actor->role === 'platform_admin' ||
                ($actor->role === 'agency' && $actor->organizationId === 'agency-a' && $request->resourceId === 'benefit');
        }
        if ($request->resourceType === 'documents.definition') {
            return in_array($actor->role, ['platform_admin', 'government_admin'], true);
        }
        if ($request->resourceType === 'doc.request') {
            return $actor->role === 'government_admin' ||
                ($actor->role === 'agency' && $actor->organizationId === 'agency-a' &&
                    in_array($request->resourceId, ['owned-1', 'owned-2'], true));
        }
        return false;
    }
}

final class DocumentsTestResources implements ResourceVerifier
{
    public function existsForService(string $serviceCode, string $resourceType, string $resourceId): bool
    {
        return $serviceCode === 'benefit' && $resourceType === 'doc.request' &&
            in_array($resourceId, ['owned-1', 'owned-2', 'foreign-1'], true);
    }
}

final class DocumentsTestReplacementPolicy implements ReplacementPolicy
{
    public string $state = 'draft';

    public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool
    {
        // Test double: production policy must query the trusted, pinned Workflow instance.
        return $this->state === 'correction_required' && $actor->role === 'agency';
    }
}

/** Sandbox-only stand-in; the host MUST bind a real scanner before production. */
final class DocumentsFixtureScanner implements FileSafetyScanner
{
    public bool $reject = false;

    public function assertSafe(string $temporaryPath, string $detectedMimeType): void
    {
        if ($this->reject) {
            throw new \RuntimeException('Scanner detected threat or is unavailable.');
        }
    }
}
