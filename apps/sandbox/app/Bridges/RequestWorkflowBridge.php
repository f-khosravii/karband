<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\Authorization\AccessRequest;
use Karband\Authorization\AuthorizerInterface;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalogLaravel\Exceptions\ServiceUnavailable;
use Karband\ServiceCatalogLaravel\Services\CatalogStorage;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;
use Karband\ServiceRequestsLaravel\Contracts\RequestWorkflowPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestWorkflowStarter;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Karband\ServiceRequestsLaravel\ValueObjects\RequestActionResult;
use Karband\ServiceRequestsLaravel\ValueObjects\RequestWorkflowInstance;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Karband\WorkflowLaravel\Services\WorkflowRuntime;
use Karband\WorkflowLaravel\Services\WorkflowStorage;

final readonly class RequestWorkflowBridge implements RequestWorkflowPort, RequestWorkflowStarter
{
    public function __construct(
        private CatalogStorage $catalog,
        private WorkflowStorage $workflowStorage,
        private WorkflowRuntime $workflow,
        private DefinitionCatalog $definitions,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorization,
        private ?\Karband\RequestJourneyLaravel\Contracts\JourneyServiceInterface $journey = null,
    ) {}

    public function start(string $serviceCode, string $resourceType, string $resourceId): RequestWorkflowInstance
    {
        ServiceDefinition::assertCode($serviceCode, 'Service code');
        $actor = $this->actors->currentActor();
        $this->authorization->authorize($actor, new AccessRequest('catalog.service.use', 'catalog.service', $serviceCode));

        $db = $this->catalog->connection;
        return $db->transaction(function () use ($db, $serviceCode, $resourceType, $resourceId): RequestWorkflowInstance {
            $row = $db->table('catalog_services')->where('code', $serviceCode)->lockForUpdate()->first();
            if ($row === null || $row->status !== ServiceStatus::Active->value) {
                throw new ServiceUnavailable('Service is not active or does not exist.');
            }
            $service = $this->catalog->hydrate($row);
            if ($service->workflow === null) {
                throw new ServiceUnavailable('Service has no workflow binding.');
            }
            $instance = $this->workflow->start(
                $service->workflow->id,
                $service->workflow->version,
                $resourceType,
                $resourceId
            );
            if ($this->journey !== null) {
                $this->journey->pinForRequest($resourceId, $serviceCode);
            }
            $wfRow = $db->table('workflow_instances')->where('id', $instance->id)->first();
            return new RequestWorkflowInstance(
                id: $instance->id,
                workflowId: $instance->workflowId,
                workflowVersion: $instance->workflowVersion,
                currentState: $instance->currentState,
                revision: $instance->revision,
                definitionRecordId: $wfRow ? (int) $wfRow->definition_id : 0,
            );
        }, ServiceCatalog::attempts());
    }

    public function lockAndVerify(string $instanceId, string $resourceType, string $resourceId): RequestWorkflowInstance
    {
        $db = $this->workflowStorage->connection;
        $row = $db->table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
        if ($row === null || $row->resource_type !== $resourceType || $row->resource_id !== $resourceId) {
            throw new RequestConflict('Workflow instance not found or resource mismatch.');
        }
        $def = $db->table('workflow_definitions')->where('id', $row->definition_id)->first();
        return new RequestWorkflowInstance(
            id: (string) $row->id,
            workflowId: $def ? (string) $def->workflow_id : '',
            workflowVersion: $def ? (int) $def->version : 0,
            currentState: (string) $row->current_state,
            revision: (int) $row->revision,
            definitionRecordId: (int) $row->definition_id,
        );
    }

    public function getDefinitionDetails(string $instanceId, string $resourceType, string $resourceId): array
    {
        $db = $this->workflowStorage->connection;
        $row = $db->table('workflow_instances')->where('id', $instanceId)->first();
        if ($row === null || $row->resource_type !== $resourceType || $row->resource_id !== $resourceId) {
            throw new \RuntimeException('Pinned workflow instance is missing or mismatched.');
        }
        $def = $db->table('workflow_definitions')->where('id', $row->definition_id)->first();
        if ($def === null) {
            throw new \RuntimeException('Pinned workflow definition is missing.');
        }
        return [
            'workflowId' => (string) $def->workflow_id,
            'workflowVersion' => (int) $def->version,
            'currentState' => (string) $row->current_state,
        ];
    }

    public function assertCancellationTransitionValid(int $definitionRecordId, string $currentState, string $action, string $expectedTargetState): void
    {
        $definition = $this->definitions->byRecordId($definitionRecordId);
        $transition = $definition->findTransition($currentState, $action);
        if ($transition === null || !in_array('government_admin', $transition->allowedRoles, true)
            || $transition->to !== $expectedTargetState) {
            throw new RequestConflict('Pinned Workflow has no valid cancellation decision transition.');
        }
    }

    public function apply(string $instanceId, string $action, ?string $comment = null, ?string $idempotencyKey = null): RequestActionResult
    {
        $outcome = $this->workflow->apply($instanceId, $action, $comment, $idempotencyKey);
        $wfRow = $outcome->instance ? $this->workflowStorage->connection->table('workflow_instances')->where('id', $outcome->instance->id)->first() : null;
        $instance = $outcome->instance ? new RequestWorkflowInstance(
            id: $outcome->instance->id,
            workflowId: $outcome->instance->workflowId,
            workflowVersion: $outcome->instance->workflowVersion,
            currentState: $outcome->instance->currentState,
            revision: $outcome->instance->revision,
            definitionRecordId: $wfRow ? (int) $wfRow->definition_id : 0,
        ) : null;

        return new RequestActionResult(
            applied: $outcome->applied,
            replayed: $outcome->replayed,
            currentState: $outcome->instance ? $outcome->instance->currentState : '',
            historyId: $outcome->historyId,
            instance: $instance,
        );
    }

    public function history(string $instanceId): array
    {
        return $this->workflow->history($instanceId);
    }

    public function connectionName(): ?string
    {
        return $this->workflowStorage->connection->getName();
    }
}
