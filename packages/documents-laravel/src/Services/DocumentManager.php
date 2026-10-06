<?php

declare(strict_types=1);

namespace Intl\DocumentsLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\Documents\Engine\DocumentValidator;
use Intl\Documents\ValueObjects\UploadDescriptor;
use Intl\DocumentsLaravel\Contracts\DocumentActionGate;
use Intl\DocumentsLaravel\Contracts\DocumentEditGate;
use Intl\DocumentsLaravel\Contracts\FileSafetyScanner;
use Intl\DocumentsLaravel\Contracts\ReplacementPolicy;
use Intl\DocumentsLaravel\Contracts\ResourceVerifier;
use Intl\DocumentsLaravel\Exceptions\DocumentConflict;
use Intl\DocumentsLaravel\Exceptions\DocumentNotFound;
use Intl\DocumentsLaravel\Exceptions\UploadRejected;
use Intl\ServiceCatalogLaravel\Services\CatalogStorage;

/**
 * No HTTP route or public URL. One private file per requirement slot; replacements append immutable history.
 * ResourceVerifier, Spatie authorization/host ScopeChecker, and ReplacementPolicy MUST be host-supplied.
 */
final readonly class DocumentManager
{
    public function __construct(
        private DocumentStorage $documents,
        private CatalogStorage $catalog,
        private PrincipalProvider $principals,
        private AuthorizerInterface $authorization,
        private ResourceVerifier $resources,
        private ReplacementPolicy $replacementPolicy,
        private FileSafetyScanner $scanner,
        private DocumentValidator $validator,
        private ?DocumentActionGate $actionGate = null,
    ) {}

    /** Pin immutable document requirements BEFORE collecting files. @return array{version:int,revision:int} */
    public function reserve(string $serviceCode, string $resourceType, string $resourceId): array
    {
        DocumentStorage::assertResource($serviceCode, $resourceType, $resourceId);
        $actor = $this->actor('documents.file.upload', $resourceType, $resourceId);
        $this->authorization->authorize($actor, new AccessRequest('catalog.service.use', 'catalog.service', $serviceCode));
        $this->assertSharedDb();
        $this->verifyResource($serviceCode, $resourceType, $resourceId);
        $db = $this->documents->connection;
        return $db->transaction(function () use ($db, $serviceCode, $resourceType, $resourceId, $actor): array {
            $service = $db->table('intl_catalog_services')->where('code', $serviceCode)->lockForUpdate()->first();
            if ($service === null || $service->status !== 'active') {
                throw new DocumentNotFound('Cannot create document set for an inactive service.');
            }
            $version = $this->documents->latestVersion($serviceCode);
            if ($version < 1) {
                throw new DocumentNotFound('Publish document requirements first (empty definition is allowed).');
            }
            $this->documents->published($serviceCode, $version);
            try {
                $db->table('intl_document_sets')->insert([
                    'service_code' => $serviceCode, 'resource_type' => $resourceType,
                    'resource_id' => $resourceId, 'definition_version' => $version,
                    'organization_id' => $actor->organizationId, 'created_by' => $actor->id,
                    'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new DocumentConflict('Document requirements already reserved for this resource.', previous: $e);
            }
            return ['version' => $version, 'revision' => 0];
        }, 1);
    }

    /**
     * Append upload, or replace one slot if trusted workflow policy permits. Always requires set revision.
     * @return array{id:string,slot:string,slot_version:int,set_revision:int,mime_type:string,size_bytes:int,sha256:string}
     */
    public function upload(string $serviceCode, string $resourceType, string $resourceId, string $slot,
        UploadedFile $file, int $expectedRevision): array
    {
        DocumentStorage::assertResource($serviceCode, $resourceType, $resourceId);
        if ($expectedRevision < 0) {
            throw new \InvalidArgumentException('Expected revision cannot be negative.');
        }
        $actor = $this->principals->current()->actor;
        $this->assertSharedDb();
        $this->verifyResource($serviceCode, $resourceType, $resourceId);
        $source = $file->getRealPath();
        if (!$file->isValid() || $source === false || !is_file($source)) {
            throw new UploadRejected('Invalid uploaded file.');
        }
        $bytes = filesize($source);
        if (!is_int($bytes) || $bytes < 1 || $bytes > (int) config('intl-documents.absolute_max_bytes')) {
            throw new UploadRejected('Upload exceeds server limit or is empty.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $hash = hash_file('sha256', $source);
        if (!is_string($mime) || !is_string($hash)) {
            throw new UploadRejected('Could not inspect upload.');
        }
        $descriptor = new UploadDescriptor($mime, $bytes, $hash);
        try {
            $this->scanner->assertSafe($source, $mime);
        } catch (\Throwable) {
            throw new UploadRejected('File safety scanner rejected upload or is unavailable.');
        }
        $diskName = $this->privateDiskName();
        $disk = Storage::disk($diskName);
        $writtenKey = null;
        try {
            return $this->documents->connection->transaction(function () use ($serviceCode, $resourceType, $resourceId,
                $slot, $file, $source, $descriptor, $expectedRevision, $actor, $disk, $diskName, &$writtenKey): array {
                $db = $this->documents->connection;
                $set = $db->table('intl_document_sets')->where('service_code', $serviceCode)
                    ->where('resource_type', $resourceType)->where('resource_id', $resourceId)->lockForUpdate()->first();
                if ($set === null) {
                    throw new DocumentNotFound('Reserve document requirements before uploading files.');
                }
                $this->assertAgencyLock($actor, $set);

                $previous = $db->table('intl_document_files')->where('set_id', $set->id)
                    ->where('requirement_code', $slot)->where('is_withdrawn', false)->orderByDesc('slot_version')->first();

                $action = $previous === null ? DocumentActionGate::ACTION_UPLOAD : DocumentActionGate::ACTION_REPLACE;
                $this->assertAction($actor, $action, $serviceCode, $resourceType, $resourceId, $slot, ['has_active' => $previous !== null]);

                if ((int) $set->revision !== $expectedRevision) {
                    throw new DocumentConflict('Stale document set revision.');
                }
                $definition = $this->documents->published($serviceCode, (int) $set->definition_version);
                $requirement = $definition->requirement($slot);
                if ($requirement === null) {
                    throw new UploadRejected('Unknown document requirement.');
                }
                $errors = $this->validator->validateUpload($requirement, $descriptor);
                if ($errors !== []) {
                    throw new UploadRejected(implode(', ', $errors));
                }

                $id = (string) Str::uuid();
                $writtenKey = 'intl-documents/' . substr($id, 0, 2) . '/' . $id;
                $stream = fopen($source, 'rb');
                if (!is_resource($stream)) {
                    throw new UploadRejected('Cannot open uploaded file.');
                }
                try {
                    if ($disk->put($writtenKey, $stream, ['visibility' => 'private']) !== true) {
                        throw new UploadRejected('Private storage write failed.');
                    }
                } finally {
                    fclose($stream);
                }
                $lastVersion = $db->table('intl_document_files')->where('set_id', $set->id)
                    ->where('requirement_code', $slot)->max('slot_version');
                $slotVersion = ($lastVersion === null ? 0 : (int) $lastVersion) + 1;
                $cleanName = self::safeFilename((string) $file->getClientOriginalName());
                $db->table('intl_document_files')->insert([
                    'id' => $id, 'set_id' => $set->id, 'requirement_code' => $slot,
                    'slot_version' => $slotVersion, 'supersedes_id' => $previous?->id,
                    'storage_disk' => $diskName, 'storage_key' => $writtenKey,
                    'original_name' => $cleanName, 'mime_type' => $descriptor->mimeType,
                    'size_bytes' => $descriptor->bytes, 'sha256' => $descriptor->sha256,
                    'uploaded_by' => $actor->id, 'actor_role' => $actor->role,
                    'is_withdrawn' => false, 'withdrawn_at' => null, 'withdrawn_by' => null, 'withdrawal_reason' => null,
                    'created_at' => now(),
                ]);
                $updated = $db->table('intl_document_sets')->where('id', $set->id)
                    ->where('revision', $expectedRevision)->update([
                        'revision' => $expectedRevision + 1, 'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new DocumentConflict('Concurrent upload detected.');
                }
                return ['id' => $id, 'slot' => $slot, 'slot_version' => $slotVersion,
                    'set_revision' => $expectedRevision + 1, 'mime_type' => $descriptor->mimeType,
                    'size_bytes' => $descriptor->bytes, 'sha256' => $descriptor->sha256];
            }, 1); // No automatic retry after an external blob write.
        } catch (\Throwable $exception) {
            // Compensate for a DB rollback. Still operate a periodic orphan reconciliation job in production.
            if ($writtenKey !== null) {
                try {
                    $disk->delete($writtenKey);
                } catch (\Throwable) {
                    // Original exception preserved; run periodic orphan cleanup in production.
                }
            }
            throw $exception;
        }
    }

    /**
     * Logically withdraw active file in slot while preserving historical evidence.
     * @return array<string, mixed>
     */
    public function withdraw(
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        ?string $reason = null,
        ?int $expectedRevision = null,
    ): array {
        DocumentStorage::assertResource($serviceCode, $resourceType, $resourceId);
        $actor = $this->actor('documents.file.withdraw', $resourceType, $resourceId);
        $this->assertSharedDb();
        $this->verifyResource($serviceCode, $resourceType, $resourceId);
        $this->assertAction($actor, DocumentActionGate::ACTION_WITHDRAW, $serviceCode, $resourceType, $resourceId, $slot);

        $db = $this->documents->connection;
        return $db->transaction(function () use ($db, $serviceCode, $resourceType, $resourceId, $slot, $reason, $expectedRevision, $actor): array {
            $set = $db->table('intl_document_sets')->where('service_code', $serviceCode)
                ->where('resource_type', $resourceType)->where('resource_id', $resourceId)->lockForUpdate()->first();
            if ($set === null) {
                throw new DocumentNotFound('Document set not found.');
            }
            $this->assertAgencyLock($actor, $set);
            if ($expectedRevision !== null && (int) $set->revision !== $expectedRevision) {
                throw new DocumentConflict('Stale document set revision.');
            }

            $active = $db->table('intl_document_files')->where('set_id', $set->id)
                ->where('requirement_code', $slot)->where('is_withdrawn', false)
                ->orderByDesc('slot_version')->lockForUpdate()->first();
            if ($active === null) {
                throw new DocumentNotFound('No active document in slot to withdraw.');
            }

            $cleanReason = $reason !== null ? mb_substr(trim($reason), 0, 1000) : null;
            $db->table('intl_document_files')->where('id', $active->id)->update([
                'is_withdrawn' => true,
                'withdrawn_at' => now(),
                'withdrawn_by' => $actor->id,
                'withdrawal_reason' => $cleanReason,
            ]);

            $newRevision = (int) $set->revision + 1;
            $db->table('intl_document_sets')->where('id', $set->id)->update([
                'revision' => $newRevision,
                'updated_at' => now(),
            ]);

            $updated = $db->table('intl_document_files')->where('id', $active->id)->first();
            return self::publicMetadata($updated);
        }, 1);
    }

    /** @return array{version:int,revision:int,missing:list<string>,files:list<array<string,mixed>>} */
    public function snapshot(
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        array $facts = [],
        ?string $currentCheckpoint = null,
    ): array {
        DocumentStorage::assertResource($serviceCode, $resourceType, $resourceId);
        $actor = $this->actor('documents.file.view', $resourceType, $resourceId);
        $this->assertSharedDb();
        [$set, $definition] = $this->findSet($serviceCode, $resourceType, $resourceId);
        $this->assertAgencyLock($actor, $set);
        $current = $this->currentRows((int) $set->id);
        return [
            'version' => $definition->version,
            'revision' => (int) $set->revision,
            'missing' => $this->validator->missingRequired($definition, array_keys($current), $facts, $currentCheckpoint),
            'files' => array_values(array_map(self::publicMetadata(...), $current)),
        ];
    }

    /** View history of all immutable uploads, including superseded and withdrawn files (no storage key disclosed). */
    public function history(string $serviceCode, string $resourceType, string $resourceId): array
    {
        DocumentStorage::assertResource($serviceCode, $resourceType, $resourceId);
        $actor = $this->actor('documents.file.view', $resourceType, $resourceId);
        [$set, ] = $this->findSet($serviceCode, $resourceType, $resourceId);
        $this->assertAgencyLock($actor, $set);
        $rows = $this->documents->connection->table('intl_document_files')->where('set_id', $set->id)
            ->orderBy('created_at')->orderBy('slot_version')->get();
        return array_map(self::publicMetadata(...), $rows->all());
    }

    /** Return an authorized PRIVATE binary stream; caller MUST fclose(). Never expose a public URL. */
    public function open(string $serviceCode, string $resourceType, string $resourceId, string $fileId): mixed
    {
        DocumentStorage::assertResource($serviceCode, $resourceType, $resourceId);
        $actor = $this->actor('documents.file.view', $resourceType, $resourceId);
        if (!Str::isUuid($fileId)) {
            throw new DocumentNotFound('File not found.');
        }
        [$set, ] = $this->findSet($serviceCode, $resourceType, $resourceId);
        $this->assertAgencyLock($actor, $set);
        $row = $this->documents->connection->table('intl_document_files')
            ->where('set_id', $set->id)->where('id', $fileId)->first();
        if ($row === null) {
            throw new DocumentNotFound('File not found for authorized request.');
        }
        $disk = $this->privateDiskName();
        if ($row->storage_disk !== $disk) {
            throw new \LogicException('Stored document disk differs from configured private disk; controlled migration required.');
        }
        $stream = Storage::disk($disk)->readStream((string) $row->storage_key);
        if (!is_resource($stream)) {
            throw new DocumentNotFound('Private file is unavailable.');
        }
        return $stream;
    }

    private function currentRows(int $setId): array
    {
        $rows = $this->documents->connection->table('intl_document_files')->where('set_id', $setId)
            ->where('is_withdrawn', false)
            ->orderBy('requirement_code')->orderByDesc('slot_version')->get();
        $slots = [];
        foreach ($rows as $row) {
            $slots[$row->requirement_code] ??= $row;
        }
        return $slots;
    }

    private function findSet(string $serviceCode, string $resourceType, string $resourceId): array
    {
        $set = $this->documents->connection->table('intl_document_sets')->where('service_code', $serviceCode)
            ->where('resource_type', $resourceType)->where('resource_id', $resourceId)->first();
        if ($set === null) {
            throw new DocumentNotFound('Document set not found.');
        }
        $definition = $this->documents->published($serviceCode, (int) $set->definition_version);
        return [$set, $definition];
    }

    private static function publicMetadata(object $row): array
    {
        return [
            'id' => $row->id,
            'slot' => $row->requirement_code,
            'slot_version' => (int) $row->slot_version,
            'supersedes_id' => $row->supersedes_id,
            'name' => $row->original_name,
            'mime_type' => $row->mime_type,
            'size_bytes' => (int) $row->size_bytes,
            'sha256' => $row->sha256,
            'uploaded_by' => $row->uploaded_by,
            'actor_role' => $row->actor_role,
            'is_withdrawn' => (bool) ($row->is_withdrawn ?? false),
            'withdrawn_at' => isset($row->withdrawn_at) && $row->withdrawn_at !== null ? (string) $row->withdrawn_at : null,
            'withdrawn_by' => isset($row->withdrawn_by) && $row->withdrawn_by !== null ? (string) $row->withdrawn_by : null,
            'withdrawal_reason' => isset($row->withdrawal_reason) && $row->withdrawal_reason !== null ? (string) $row->withdrawal_reason : null,
            'created_at' => (string) $row->created_at,
        ];
    }

    private function verifyResource(string $serviceCode, string $resourceType, string $resourceId): void
    {
        try {
            if ($this->resources->existsForService($serviceCode, $resourceType, $resourceId)) {
                return;
            }
        } catch (\Throwable) {
            throw new AuthorizationDenied('document_resource_invalid');
        }
        throw new AuthorizationDenied('document_resource_invalid');
    }

    private function assertAgencyLock(ActorContext $actor, object $set): void
    {
        // Host scope must check actual ownership for ALL roles; this is an extra agency-specific defense.
        if ($actor->role === 'agency' &&
            ($set->organization_id === null || $actor->organizationId !== (string) $set->organization_id)) {
            throw new AuthorizationDenied('document_owner_mismatch');
        }
    }

    private function actor(string $ability, string $resourceType, string $resourceId): ActorContext
    {
        $actor = $this->principals->current()->actor;
        $this->authorization->authorize($actor, new AccessRequest($ability, $resourceType, $resourceId));
        return $actor;
    }

    private function assertAction(
        ActorContext $actor,
        string $action,
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        array $context = [],
    ): void {
        if ($this->actionGate !== null) {
            $this->actionGate->assertAllowed($actor, $action, $serviceCode, $resourceType, $resourceId, $slot, $context);
            return;
        }

        $permission = match ($action) {
            DocumentActionGate::ACTION_VIEW => 'documents.file.view',
            DocumentActionGate::ACTION_UPLOAD => 'documents.file.upload',
            DocumentActionGate::ACTION_REPLACE => 'documents.file.replace',
            DocumentActionGate::ACTION_WITHDRAW => 'documents.file.withdraw',
            default => throw new \InvalidArgumentException("Unknown document action: {$action}"),
        };

        $this->authorization->authorize($actor, new AccessRequest($permission, $resourceType, $resourceId));

        if ($action === DocumentActionGate::ACTION_REPLACE) {
            try {
                $allowed = $this->replacementPolicy->allows($actor, $serviceCode, $resourceType, $resourceId, $slot);
            } catch (\Throwable) {
                throw new AuthorizationDenied('replacement_policy_denied');
            }
            if (!$allowed) {
                throw new AuthorizationDenied('replacement_not_allowed');
            }
        }

        if (in_array($action, [DocumentActionGate::ACTION_UPLOAD, DocumentActionGate::ACTION_REPLACE, DocumentActionGate::ACTION_WITHDRAW], true)) {
            if ($resourceType === 'intl.service_request' && app()->bound(DocumentEditGate::class)) {
                app(DocumentEditGate::class)->assertDocumentSlot($serviceCode, $resourceType, $resourceId, $slot, $action);
            }
        }
    }

    private function assertSharedDb(): void
    {
        if ($this->documents->connection !== $this->catalog->connection) {
            throw new \LogicException('Documents and Catalog must share the same local DB connection.');
        }
    }

    private function privateDiskName(): string
    {
        $name = config('intl-documents.private_disk');
        if (!is_string($name) || $name === '' || $name === 'public' ||
            config("filesystems.disks.{$name}.visibility") === 'public' ||
            config("filesystems.disks.{$name}") === null) {
            throw new \LogicException('Configure an explicit private document disk, not the public disk.');
        }
        $root = config("filesystems.disks.{$name}.root");
        $public = realpath(public_path());
        if (is_string($root) && $public !== false && (realpath($root) === $public ||
            str_starts_with((string) realpath($root), $public . DIRECTORY_SEPARATOR))) {
            throw new \LogicException('Document storage cannot be inside the public web root.');
        }
        return $name;
    }

    private static function safeFilename(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
        $name = trim($name);
        return $name === '' ? 'document' : substr($name, 0, 190);
    }
}
