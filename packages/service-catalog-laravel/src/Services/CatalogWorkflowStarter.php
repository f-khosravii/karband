<?php

declare(strict_types=1);

namespace Intl\ServiceCatalogLaravel\Services;

use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\ServiceCatalog\Definitions\ServiceDefinition;
use Intl\ServiceCatalog\Definitions\ServiceStatus;
use Intl\ServiceCatalogLaravel\Exceptions\ServiceUnavailable;
use Intl\WorkflowLaravel\Services\WorkflowRuntime;
use Intl\WorkflowLaravel\Services\WorkflowStorage;
use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;

/**
 * Use for new requests. Locks the local catalog row while starting a pinned
 * Workflow instance in the SAME DB transaction and connection.
 * The host must separately enforce ownership on the resource being started.
 */
final readonly class CatalogWorkflowStarter
{
    public function __construct(
        private CatalogStorage $catalog,
        private WorkflowStorage $workflowStorage,
        private WorkflowRuntime $workflow,
        private PrincipalProvider $principals,
        private AuthorizerInterface $authorization,
    ) {
    }

    public function start(string $serviceCode, string $resourceType, string $resourceId): WorkflowInstance
    {
        ServiceDefinition::assertCode($serviceCode, 'Service code');
        if ($this->catalog->connection !== $this->workflowStorage->connection) {
            throw new \LogicException('Catalog and Workflow must use the same Laravel DB connection for atomic starts.');
        }
        $actor = $this->principals->current()->actor;
        $this->authorization->authorize($actor,
            new AccessRequest('catalog.service.use', 'catalog.service', $serviceCode));

        $db = $this->catalog->connection;
        return $db->transaction(function () use ($db, $serviceCode, $resourceType, $resourceId): WorkflowInstance {
            $row = $db->table('intl_catalog_services')->where('code', $serviceCode)->lockForUpdate()->first();
            if ($row === null || $row->status !== ServiceStatus::Active->value) {
                throw new ServiceUnavailable('Service is not active or does not exist.');
            }
            $service = $this->catalog->hydrate($row);
            if ($service->workflow === null) {
                throw new ServiceUnavailable('Service has no workflow binding.');
            }
            // WorkflowRuntime separately re-authenticates the current actor,
            // checks workflow.instance.start on the host resource and pins version.
            return $this->workflow->start($service->workflow->id, $service->workflow->version,
                $resourceType, $resourceId);
        }, ServiceCatalog::attempts());
    }
}
