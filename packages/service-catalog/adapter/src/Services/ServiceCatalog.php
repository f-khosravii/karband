<?php

declare(strict_types=1);

namespace Karband\ServiceCatalogLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\AuthorizerInterface;
use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalogLaravel\Contracts\WorkflowDefinitionVerifier;
use Karband\ServiceCatalogLaravel\Exceptions\ServiceConflict;
use Karband\ServiceCatalogLaravel\Exceptions\ServiceNotFound;
use Karband\ServiceCatalogLaravel\Exceptions\ServiceUnavailable;
use Karband\ServiceCatalogLaravel\Exceptions\StaleService;
use Karband\ServiceCatalogLaravel\ValueObjects\PublicService;

/**
 * One isolated microservice's catalog. Authorization is always based on the
 * authenticated principal and that host's ScopeChecker; never caller-supplied roles.
 */
final readonly class ServiceCatalog
{
    public function __construct(
        private CatalogStorage $storage,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorization,
        private WorkflowDefinitionVerifier $workflows,
    ) {
    }

    public function create(ServiceDraft $draft): ServiceDefinition
    {
        $this->authorize('catalog.service.create', 'catalog.service', $draft->code);
        $service = ServiceDefinition::fromDraft($draft);
        $db = $this->storage->connection;

        return $db->transaction(function () use ($db, $service): ServiceDefinition {
            if ($db->table('catalog_services')->where('code', $service->code)->exists()) {
                throw new ServiceConflict('Service code already exists.');
            }
            $this->verifyWorkflow($service);
            try {
                $db->table('catalog_services')->insert(array_merge(
                    ['code' => $service->code, 'created_at' => now()], CatalogStorage::columns($service)
                ));
            } catch (UniqueConstraintViolationException $e) {
                throw new ServiceConflict('Service code already exists.', previous: $e);
            }
            return $service;
        }, self::attempts());
    }

    /** Authorization precedes the lookup; no unauthenticated code enumeration. */
    public function get(string $code): ServiceDefinition
    {
        ServiceDefinition::assertCode($code, 'Service code');
        $this->authorize('catalog.service.view', 'catalog.service', $code);
        $row = $this->storage->connection->table('catalog_services')->where('code', $code)->first();
        if ($row === null) {
            throw new ServiceNotFound('Service not found.');
        }
        return $this->storage->hydrate($row);
    }

    /** Fully replaces editable values; service code cannot be changed. */
    public function replace(string $code, int $expectedRevision, ServiceDraft $draft): ServiceDefinition
    {
        ServiceDefinition::assertCode($code, 'Service code');
        if ($code !== $draft->code) {
            throw new \InvalidArgumentException('Service code cannot be changed.');
        }
        $this->authorize('catalog.service.update', 'catalog.service', $code);
        return $this->mutate($code, $expectedRevision, function (ServiceDefinition $previous) use ($draft): ServiceDefinition {
            $updated = $previous->replace($draft);
            $this->verifyWorkflow($updated);
            return $updated;
        });
    }

    public function activate(string $code, int $expectedRevision): ServiceDefinition
    {
        return $this->changeStatus($code, $expectedRevision, ServiceStatus::Active, 'catalog.service.activate');
    }

    public function deactivate(string $code, int $expectedRevision): ServiceDefinition
    {
        return $this->changeStatus($code, $expectedRevision, ServiceStatus::Inactive, 'catalog.service.deactivate');
    }

    public function archive(string $code, int $expectedRevision): ServiceDefinition
    {
        return $this->changeStatus($code, $expectedRevision, ServiceStatus::Archived, 'catalog.service.archive');
    }

    /**
     * Entry for permissioned orchestration outside this adapter. For atomic
     * deactivation/start semantics, use CatalogWorkflowStarter rather than
     * separating this lookup and WorkflowRuntime::start into different txns.
     */
    public function requireForUse(string $code): ServiceDefinition
    {
        ServiceDefinition::assertCode($code, 'Service code');
        $this->authorize('catalog.service.use', 'catalog.service', $code);
        $row = $this->storage->connection->table('catalog_services')->where('code', $code)->first();
        if ($row === null || $row->status !== ServiceStatus::Active->value) {
            throw new ServiceUnavailable('Service is not active or does not exist.');
        }
        $service = $this->storage->hydrate($row);
        if ($service->workflow === null) {
            throw new ServiceUnavailable('Service has no workflow binding.');
        }
        return $service;
    }

    /**
     * Agency-safe discovery, subject to explicit LIST and individual USE
     * permissions. Never returns internal settings or workflow IDs.
     * @return list<PublicService>
     */
    public function available(int $limit = 100, int $offset = 0): array
    {
        self::pagination($limit, $offset);
        $this->authorize('catalog.service.list', 'catalog.collection', 'local');
        $rows = $this->storage->connection->table('catalog_services')
            ->where('status', ServiceStatus::Active->value)
            ->orderBy('code')->offset($offset)->limit($limit)->get();
        $results = [];
        foreach ($rows as $row) {
            try {
                $this->authorize('catalog.service.use', 'catalog.service', (string) $row->code);
            } catch (\Karband\Authorization\AuthorizationDenied) {
                continue;
            }
            $results[] = new PublicService((string) $row->code, (string) $row->name,
                $row->description === null ? null : (string) $row->description);
        }
        return $results;
    }

    /**
     * Full administrative listing (including inactive/archived), respecting
     * per-service VIEW scope. List authorization is required first.
     * @return list<ServiceDefinition>
     */
    public function listAuthorized(int $limit = 100, int $offset = 0): array
    {
        self::pagination($limit, $offset);
        $this->authorize('catalog.service.list', 'catalog.collection', 'local');
        $rows = $this->storage->connection->table('catalog_services')
            ->orderBy('code')->offset($offset)->limit($limit)->get();
        $results = [];
        foreach ($rows as $row) {
            try {
                $this->authorize('catalog.service.view', 'catalog.service', (string) $row->code);
            } catch (\Karband\Authorization\AuthorizationDenied) {
                continue;
            }
            $results[] = $this->storage->hydrate($row);
        }
        return $results;
    }

    private function changeStatus(string $code, int $expectedRevision, ServiceStatus $status, string $permission): ServiceDefinition
    {
        ServiceDefinition::assertCode($code, 'Service code');
        $this->authorize($permission, 'catalog.service', $code);
        return $this->mutate($code, $expectedRevision, function (ServiceDefinition $previous) use ($status): ServiceDefinition {
            $updated = $previous->transition($status);
            if ($status === ServiceStatus::Active) {
                $this->verifyWorkflow($updated);
            }
            return $updated;
        });
    }

    /** All writes lock + compare revision + persist within the same local DB transaction. */
    private function mutate(string $code, int $expectedRevision, \Closure $change): ServiceDefinition
    {
        if ($expectedRevision < 0) {
            throw new \InvalidArgumentException('Expected revision cannot be negative.');
        }
        $db = $this->storage->connection;
        return $db->transaction(function () use ($db, $code, $expectedRevision, $change): ServiceDefinition {
            $row = $db->table('catalog_services')->where('code', $code)->lockForUpdate()->first();
            if ($row === null) {
                throw new ServiceNotFound('Service not found.');
            }
            $previous = $this->storage->hydrate($row);
            if ($previous->revision !== $expectedRevision) {
                throw new StaleService('Service was changed; load the latest revision before retrying.');
            }
            $next = $change($previous);
            $written = $db->table('catalog_services')->where('code', $code)
                ->where('revision', $expectedRevision)->update(CatalogStorage::columns($next));
            if ($written !== 1) {
                throw new StaleService('Concurrent change detected; retry with a fresh revision.');
            }
            return $next;
        }, self::attempts());
    }

    private function verifyWorkflow(ServiceDefinition $service): void
    {
        if ($service->workflow !== null) {
            $this->workflows->assertPublished($service->workflow);
        }
    }

    private function authorize(string $ability, string $resourceType, string $id): void
    {
        $actor = $this->actors->currentActor();
        $this->authorization->authorize($actor, new AccessRequest($ability, $resourceType, $id));
    }

    private static function pagination(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 200 || $offset < 0) {
            throw new \InvalidArgumentException('Limit must be 1-200 and offset nonnegative.');
        }
    }

    public static function attempts(): int
    {
        $attempts = config('service-catalog.deadlock_attempts', 3);
        if (!is_int($attempts) || $attempts < 1 || $attempts > 5) {
            throw new \InvalidArgumentException('Catalog deadlock_attempts must be an integer from 1 to 5.');
        }
        return $attempts;
    }
}
