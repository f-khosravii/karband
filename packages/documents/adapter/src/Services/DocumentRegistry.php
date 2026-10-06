<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\AuthorizerInterface;
use Karband\Documents\Definitions\DocumentDefinition;
use Karband\DocumentsLaravel\Exceptions\DocumentConflict;
use Karband\DocumentsLaravel\Contracts\CatalogServiceVerifier;

/** Editable draft, immutable published versions, no executable policy in definition data. */
final readonly class DocumentRegistry
{
    public function __construct(
        private DocumentStorage $documents,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorization,
        private ?CatalogServiceVerifier $catalog = null,
    ) {}

    public function saveDraft(DocumentDefinition $definition, ?int $expectedRevision = null): int
    {
        $actor = $this->actor('documents.definition.edit', $definition->serviceCode);
        $this->assertSharedDb();
        $this->assertAllowlist($definition);
        $db = $this->documents->connection;
        return $db->transaction(function () use ($db, $definition, $expectedRevision, $actor): int {
            $this->assertService($definition->serviceCode, true);
            if ($definition->version !== $this->documents->latestVersion($definition->serviceCode) + 1) {
                throw new DocumentConflict('Draft must use next definition version.');
            }
            $query = $db->table('document_drafts')->where('service_code', $definition->serviceCode);
            $row = $query->lockForUpdate()->first();
            if ($row === null) {
                if ($expectedRevision !== null) {
                    throw new DocumentConflict('Missing draft at expected revision.');
                }
                try {
                    $db->table('document_drafts')->insert([
                        'service_code' => $definition->serviceCode, 'version' => $definition->version,
                        'definition' => DocumentStorage::encode($definition), 'revision' => 0,
                        'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    throw new DocumentConflict('Concurrent draft created.', previous: $e);
                }
                return 0;
            }
            if ($expectedRevision === null || (int) $row->revision !== $expectedRevision) {
                throw new DocumentConflict('Stale draft revision.');
            }
            $updated = $db->table('document_drafts')->where('service_code', $definition->serviceCode)
                ->where('revision', $expectedRevision)->update([
                    'definition' => DocumentStorage::encode($definition), 'revision' => $expectedRevision + 1,
                    'updated_by' => $actor->id, 'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DocumentConflict('Concurrent draft update.');
            }
            return $expectedRevision + 1;
        }, 1);
    }

    /** @return array{definition:DocumentDefinition,revision:int} */
    public function draft(string $serviceCode): array
    {
        $this->actor('documents.definition.view', $serviceCode);
        $row = $this->documents->connection->table('document_drafts')->where('service_code', $serviceCode)->first();
        if ($row === null) {
            throw new DocumentNotFound('Draft not found.');
        }
        return ['definition' => DocumentStorage::decode((string) $row->definition), 'revision' => (int) $row->revision];
    }

    public function publishDraft(string $serviceCode, int $expectedRevision): DocumentDefinition
    {
        $actor = $this->actor('documents.definition.publish', $serviceCode);
        $this->assertSharedDb();
        $db = $this->documents->connection;
        return $db->transaction(function () use ($db, $serviceCode, $expectedRevision, $actor): DocumentDefinition {
            $this->assertService($serviceCode, true);
            $row = $db->table('document_drafts')->where('service_code', $serviceCode)->lockForUpdate()->first();
            if ($row === null || (int) $row->revision !== $expectedRevision) {
                throw new DocumentConflict('Draft was changed before publishing.');
            }
            $definition = DocumentStorage::decode((string) $row->definition);
            $this->assertAllowlist($definition);
            if ($definition->serviceCode !== $serviceCode || $definition->version !== $this->documents->latestVersion($serviceCode) + 1) {
                throw new DocumentConflict('Draft no longer matches the next version.');
            }
            try {
                $db->table('document_definitions')->insert([
                    'service_code' => $serviceCode, 'version' => $definition->version,
                    'definition' => DocumentStorage::encode($definition), 'published_by' => $actor->id,
                    'published_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new DocumentConflict('Document definition already published.', previous: $e);
            }
            if ($db->table('document_drafts')->where('service_code', $serviceCode)
                ->where('revision', $expectedRevision)->delete() !== 1) {
                throw new DocumentConflict('Draft changed before deletion.');
            }
            return $definition;
        }, 1);
    }

    public function published(string $serviceCode, int $version): DocumentDefinition
    {
        $this->actor('documents.definition.view', $serviceCode);
        return $this->documents->published($serviceCode, $version);
    }

    /** Only ACTIVE, catalog-entitled services are exposed to agencies. */
    public function currentForAgency(string $serviceCode): DocumentDefinition
    {
        $actor = $this->actor('catalog.service.use', $serviceCode, 'catalog.service');
        $this->assertSharedDb();
        $this->assertService($serviceCode, false, true);
        return $this->documents->published($serviceCode, $this->documents->latestVersion($serviceCode));
    }

    private function assertAllowlist(DocumentDefinition $definition): void
    {
        $allowed = config('documents.allowed_mime_types');
        $max = config('documents.absolute_max_bytes');
        if (!is_array($allowed) || $allowed === [] || !is_int($max) || $max < 1) {
            throw new \LogicException('Invalid document MIME/size security configuration.');
        }
        foreach ($definition->requirements as $r) {
            if ($r->maxBytes > $max || array_diff($r->allowedMimeTypes, $allowed) !== []) {
                throw new DocumentConflict('Requirement exceeds server-allowed file limits.');
            }
        }
    }

    private function assertSharedDb(): void
    {
    }

    private function assertService(string $serviceCode, bool $lock, bool $requireActive = false): void
    {
        if ($this->catalog !== null) {
            $this->catalog->assertAvailable($serviceCode, $lock, $requireActive);
        }
    }

    private function actor(string $ability, string $id, string $type = 'documents.definition'): \Karband\Authorization\ActorContext
    {
        $actor = $this->actors->currentActor();
        $this->authorization->authorize($actor, new AccessRequest($ability, $type, $id));
        return $actor;
    }
}
