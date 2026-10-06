<?php

declare(strict_types=1);

namespace Karband\DynamicFormsLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\AuthorizerInterface;
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Engine\FormValidator;
use Karband\DynamicFormsLaravel\Exceptions\FormConflict;
use Karband\DynamicFormsLaravel\Contracts\FormCatalogVerifier;

/** Draft editor and immutable publication API for a future authenticated admin panel. */
final readonly class FormRegistry
{
    public function __construct(
        private FormStorage $forms,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $auth,
        private FormValidator $validator,
        private ?FormCatalogVerifier $catalog = null,
    ) {
    }

    public function saveDraft(FormDefinition $form, ?int $expectedRevision = null): int
    {
        $actor = $this->principal('forms.definition.edit', $form->serviceCode);
        $this->assertSameConnection();
        $db = $this->forms->connection;
        return $db->transaction(function () use ($form, $actor, $expectedRevision, $db): int {
            $this->assertServiceExists($form->serviceCode, true);
            $latest = $this->latestVersion($form->serviceCode);
            if ($form->version !== $latest + 1) {
                throw new FormConflict('Draft must use the next form version.');
            }
            $row = $db->table('form_drafts')->where('service_code', $form->serviceCode)->lockForUpdate()->first();
            $json = FormStorage::json($form);
            if ($row === null) {
                if ($expectedRevision !== null) {
                    throw new FormConflict('Draft does not exist at the expected revision.');
                }
                try {
                    $db->table('form_drafts')->insert([
                        'service_code' => $form->serviceCode, 'version' => $form->version,
                        'definition' => $json, 'revision' => 0,
                        'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    throw new FormConflict('Another draft already exists.', previous: $e);
                }
                return 0;
            }
            if ($expectedRevision === null || (int) $row->revision !== $expectedRevision) {
                throw new FormConflict('Draft changed since it was loaded.');
            }
            $changed = $db->table('form_drafts')->where('service_code', $form->serviceCode)
                ->where('revision', $expectedRevision)->update([
                    'version' => $form->version, 'definition' => $json,
                    'revision' => $expectedRevision + 1, 'updated_by' => $actor->id, 'updated_at' => now(),
                ]);
            if ($changed !== 1) {
                throw new FormConflict('Concurrent draft modification detected.');
            }
            return $expectedRevision + 1;
        }, FormStorage::attempts());
    }

    /** @return array{form:FormDefinition,revision:int} */
    public function draft(string $serviceCode): array
    {
        $this->principal('forms.definition.view', $serviceCode);
        $row = $this->forms->connection->table('form_drafts')->where('service_code', $serviceCode)->first();
        if ($row === null) {
            throw new FormNotFound('No draft for service.');
        }
        return ['form' => $this->forms->hydrate($row), 'revision' => (int) $row->revision];
    }

    public function publishDraft(string $serviceCode, int $expectedRevision): FormDefinition
    {
        $actor = $this->principal('forms.definition.publish', $serviceCode);
        $this->assertSameConnection();
        $db = $this->forms->connection;
        return $db->transaction(function () use ($db, $serviceCode, $actor, $expectedRevision): FormDefinition {
            // Local service row is a serialization point for concurrent publishes.
            $this->assertServiceExists($serviceCode, true);
            $row = $db->table('form_drafts')->where('service_code', $serviceCode)->lockForUpdate()->first();
            if ($row === null || (int) $row->revision !== $expectedRevision) {
                throw new FormConflict('Draft revision does not match.');
            }
            $form = $this->forms->hydrate($row);
            $this->validator->assertPublishable($form);
            if ($form->version !== $this->latestVersion($serviceCode) + 1) {
                throw new FormConflict('Another form version was published.');
            }
            try {
                $db->table('form_definitions')->insert([
                    'service_code' => $serviceCode, 'version' => $form->version,
                    'definition' => FormStorage::json($form),
                    'published_by' => $actor->id, 'published_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new FormConflict('Form version already published.', previous: $e);
            }
            $deleted = $db->table('form_drafts')->where('service_code', $serviceCode)
                ->where('revision', $expectedRevision)->delete();
            if ($deleted !== 1) {
                throw new FormConflict('Draft changed during publication.');
            }
            return $form;
        }, FormStorage::attempts());
    }

    public function published(string $serviceCode, int $version): FormDefinition
    {
        $this->principal('forms.definition.view', $serviceCode);
        return $this->loadPublished($serviceCode, $version);
    }

    /** Used internally by submissions; authentication/authorization happens there. */
    public function loadPublished(string $serviceCode, int $version): FormDefinition
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('Form version must be positive.');
        }
        $row = $this->forms->connection->table('form_definitions')
            ->where('service_code', $serviceCode)->where('version', $version)->first();
        if ($row === null) {
            throw new FormNotFound('Published form version not found.');
        }
        $form = $this->forms->hydrate($row);
        $this->validator->assertPublishable($form);
        return $form;
    }

    /** Agency-facing form: only currently active, entitled services. */
    public function currentForAgency(string $serviceCode): FormDefinition
    {
        $this->principal('catalog.service.use', $serviceCode, 'catalog.service');
        if ($this->catalog !== null) {
            $this->catalog->assertAvailable($serviceCode, false, true);
        }
        $version = $this->latestVersion($serviceCode);
        return $this->loadPublished($serviceCode, $version);
    }

    public function latestVersion(string $serviceCode): int
    {
        return (int) ($this->forms->connection->table('form_definitions')
            ->where('service_code', $serviceCode)->max('version') ?? 0);
    }

    public function serviceIsActive(string $serviceCode): bool
    {
        if ($this->catalog === null) {
            return true;
        }
        try {
            $this->catalog->assertAvailable($serviceCode, false, true);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertServiceExists(string $serviceCode, bool $lock = false): void
    {
        if ($this->catalog !== null) {
            $this->catalog->assertAvailable($serviceCode, $lock, false);
        }
    }

    private function assertSameConnection(): void
    {
    }

    private function principal(string $ability, string $id, string $resourceType = 'forms.definition'): \Karband\Authorization\ActorContext
    {
        $actor = $this->actors->currentActor();
        $this->auth->authorize($actor, new AccessRequest($ability, $resourceType, $id));
        return $actor;
    }
}
