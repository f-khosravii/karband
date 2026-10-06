<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\Authorization\AccessRequest;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AuthorizerInterface;
use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalogLaravel\Exceptions\ServiceUnavailable;
use Karband\ServiceCatalogLaravel\Services\CatalogStorage;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\WorkflowLaravel\Services\WorkflowRuntime;
use Karband\WorkflowLaravel\Services\WorkflowStorage;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;

/**
 * Host Bridge: Locks the local catalog row while starting a pinned
 * Workflow instance in the SAME DB transaction and connection.
 * The host must separately enforce ownership on the resource being started.
 */
final readonly class CatalogWorkflowStarter
{
    public function __construct(
        private CatalogStorage $catalog,
        private WorkflowStorage $workflowStorage,
        private WorkflowRuntime $workflow,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorization,
    ) {
    }

    public function start(string $serviceCode, string $resourceType, string $resourceId): WorkflowInstance
    {
        ServiceDefinition::assertCode($serviceCode, 'Service code');
        if ($this->catalog->connection !== $this->workflowStorage->connection) {
            throw new \LogicException('Catalog and Workflow must use the same Laravel DB connection for atomic starts.');
        }
        $actor = $this->actors->currentActor();
        $this->authorization->authorize($actor,
            new AccessRequest('catalog.service.use', 'catalog.service', $serviceCode));

        $db = $this->catalog->connection;
        return $db->transaction(function () use ($db, $serviceCode, $resourceType, $resourceId): WorkflowInstance {
            $row = $db->table('catalog_services')->where('code', $serviceCode)->lockForUpdate()->first();
            if ($row === null || $row->status !== ServiceStatus::Active->value) {
                throw new ServiceUnavailable('Service is not active or does not exist.');
            }
            $service = $this->catalog->hydrate($row);
            if ($service->workflow === null) {
                throw new ServiceUnavailable('Service has no workflow binding.');
            }
            return $this->workflow->start(
                $service->workflow->id,
                $service->workflow->version,
                $resourceType,
                $resourceId
            );
        }, ServiceCatalog::attempts());
    }
}
