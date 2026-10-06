<?php

declare(strict_types=1);

namespace Karband\DynamicFormsLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\AuthorizerInterface;
use Karband\DynamicForms\Engine\FormValidator;
use Karband\DynamicFormsLaravel\Exceptions\AnswerValidationFailed;
use Karband\DynamicFormsLaravel\Exceptions\FormConflict;
use Karband\DynamicFormsLaravel\Exceptions\FormNotFound;
use Karband\DynamicFormsLaravel\Contracts\FormCatalogVerifier;
use Karband\DynamicFormsLaravel\Contracts\ResponseEditGate;

/** Submissions are pinned to first published version used for their resource. */
final readonly class FormAnswers
{
    public function __construct(
        private FormStorage $forms,
        private FormRegistry $registry,
        private FormValidator $validator,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $auth,
        private ?FormCatalogVerifier $catalog = null,
    ) {
    }

    /**
     * Pin a form version at resource creation, BEFORE the first answer is entered.
     * The host should wrap reserve() and CatalogWorkflowStarter::start() in one
     * transaction on the SAME local DB connection for consistent request creation.
     * @return array{version:int,revision:int,answers:array<string,mixed>}
     */
    public function reserve(string $serviceCode, string $resourceType, string $resourceId): array
    {
        $this->assertResource($resourceType, $resourceId);
        $actor = $this->principal('forms.response.submit', $resourceType, $resourceId);
        $this->auth->authorize($actor, new AccessRequest('catalog.service.use', 'catalog.service', $serviceCode));
        $db = $this->forms->connection;
        return $db->transaction(function () use ($db, $serviceCode, $resourceType, $resourceId, $actor): array {
            if ($this->catalog !== null) {
                $this->catalog->assertAvailable($serviceCode, true, true);
            }
            $version = $this->registry->latestVersion($serviceCode);
            if ($version < 1) {
                throw new FormNotFound('Service has no published form.');
            }
            try {
                $db->table('form_answers')->insert([
                    'service_code' => $serviceCode, 'resource_type' => $resourceType,
                    'resource_id' => $resourceId, 'form_version' => $version,
                    'answers' => '{}', 'revision' => 0, 'created_by' => $actor->id,
                    'updated_by' => $actor->id, 'organization_id' => $actor->organizationId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new FormConflict('Form is already reserved for this resource.', previous: $e);
            }
            return ['version' => $version, 'revision' => 0, 'answers' => []];
        }, FormStorage::attempts());
    }

    /** @param array<string,mixed> $input
     * @return array{version:int,revision:int,answers:array<string,mixed>}
     */
    public function save(string $serviceCode, string $resourceType, string $resourceId, array $input, ?int $expectedRevision = null): array
    {
        $this->assertResource($resourceType, $resourceId);
        $actor = $this->principal('forms.response.submit', $resourceType, $resourceId);
        $this->auth->authorize($actor, new AccessRequest('catalog.service.use', 'catalog.service', $serviceCode));
        $db = $this->forms->connection;
        return $db->transaction(function () use ($db, $serviceCode, $resourceType, $resourceId, $input, $expectedRevision, $actor): array {
            if ($this->catalog !== null) {
                $this->catalog->assertAvailable($serviceCode, true, false);
            }
            // Gate locks REQUEST BEFORE form answers, consistently with recipient draft sync.
            // The gate itself loads stored answers under the request row lock to compare changed keys.
            if ($resourceType === 'service_request') {
                if (!app()->bound(ResponseEditGate::class)) {
                    throw new FormConflict('Request response edit gate is not installed.');
                }
                app(ResponseEditGate::class)->assertResponseAllowed($serviceCode,$resourceType,$resourceId,$input);
            }
            $row = $db->table('form_answers')->where('service_code', $serviceCode)
                ->where('resource_type', $resourceType)->where('resource_id', $resourceId)->lockForUpdate()->first();
            if ($row === null) {
                if ($expectedRevision !== null) {
                    throw new FormConflict('Answer set does not exist at the requested revision.');
                }
                if ($this->catalog !== null) {
                    $this->catalog->assertAvailable($serviceCode, true, true);
                }
                $version = $this->registry->latestVersion($serviceCode);
                if ($version < 1) {
                    throw new FormNotFound('Service has no published form.');
                }
            } else {
                if ($expectedRevision === null || (int) $row->revision !== $expectedRevision) {
                    throw new FormConflict('Answers have changed since they were loaded.');
                }
                // Further edits to existing resource allowed after service deactivation,
                // but always use the ORIGINAL pinned form definition.
                $version = (int) $row->form_version;
            }
            $form = $this->registry->loadPublished($serviceCode, $version);
            $normalizedInput = \Karband\DynamicForms\Engine\ItemIdentityNormalizer::ensureItemIds($form, $input, static fn (): string => (string) \Illuminate\Support\Str::ulid());
            $result = $this->validator->validate($form, $normalizedInput);
            if (!$result->valid()) {
                throw new AnswerValidationFailed($result->errors);
            }
            $json = json_encode($result->answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($row === null) {
                try {
                    $db->table('form_answers')->insert([
                        'service_code' => $serviceCode, 'resource_type' => $resourceType,
                        'resource_id' => $resourceId, 'form_version' => $version,
                        'answers' => $json, 'revision' => 0, 'created_by' => $actor->id,
                        'updated_by' => $actor->id, 'organization_id' => $actor->organizationId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    throw new FormConflict('An answer set was created concurrently.', previous: $e);
                }
                return ['version' => $version, 'revision' => 0, 'answers' => $result->answers];
            }
            // Ownership is ALWAYS host-scoped, plus a defensive organization lock for agency-authored answers.
            if ($row->organization_id !== null && $actor->organizationId !== (string) $row->organization_id) {
                throw new FormConflict('Cannot change answers created for another organization.');
            }
            $updated = $db->table('form_answers')->where('id', $row->id)
                ->where('revision', $expectedRevision)->update([
                    'answers' => $json, 'revision' => $expectedRevision + 1,
                    'updated_by' => $actor->id, 'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new FormConflict('Concurrent answer modification detected.');
            }
            return ['version' => $version, 'revision' => $expectedRevision + 1, 'answers' => $result->answers];
        }, FormStorage::attempts());
    }

    /** @return array{version:int,revision:int,answers:array<string,mixed>} */
    public function read(string $serviceCode, string $resourceType, string $resourceId): array
    {
        $this->assertResource($resourceType, $resourceId);
        $this->principal('forms.response.view', $resourceType, $resourceId);
        $row = $this->forms->connection->table('form_answers')->where('service_code', $serviceCode)
            ->where('resource_type', $resourceType)->where('resource_id', $resourceId)->first();
        if ($row === null) {
            throw new FormNotFound('Answer set not found.');
        }
        $answers = json_decode((string) $row->answers, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($answers)) {
            throw new \UnexpectedValueException('Stored answers were corrupted.');
        }
        // Detect definition corruption rather than silently read against wrong schema.
        $this->registry->loadPublished($serviceCode, (int) $row->form_version);
        return ['version' => (int) $row->form_version, 'revision' => (int) $row->revision, 'answers' => $answers];
    }

    private function assertResource(string $resourceType, string $resourceId): void
    {
        foreach ([$resourceType, $resourceId] as $id) {
            if ($id === '' || strlen($id) > 191) {
                throw new \InvalidArgumentException('Resource identifier must contain 1-191 characters.');
            }
        }
        if (strlen($resourceType) > 120) {
            throw new \InvalidArgumentException('Resource type exceeds 120 characters.');
        }
    }

    private function principal(string $ability, string $resourceType, string $resourceId): \Karband\Authorization\ActorContext
    {
        $actor = $this->actors->currentActor();
        $this->auth->authorize($actor, new AccessRequest($ability, $resourceType, $resourceId));
        return $actor;
    }
}
