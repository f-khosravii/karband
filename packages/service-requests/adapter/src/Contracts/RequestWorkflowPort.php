<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

use Karband\ServiceRequestsLaravel\ValueObjects\RequestActionResult;
use Karband\ServiceRequestsLaravel\ValueObjects\RequestWorkflowInstance;

interface RequestWorkflowPort
{
    public function start(string $serviceCode, string $resourceType, string $resourceId): RequestWorkflowInstance;

    public function lockAndVerify(string $instanceId, string $resourceType, string $resourceId): RequestWorkflowInstance;

    /** @return array{workflowId: string, workflowVersion: int} */
    public function getDefinitionDetails(string $instanceId, string $resourceType, string $resourceId): array;

    public function assertCancellationTransitionValid(int $definitionRecordId, string $currentState, string $action, string $expectedTargetState): void;

    public function apply(string $instanceId, string $action, ?string $comment = null, ?string $idempotencyKey = null): RequestActionResult;

    /** @return array<int, array<string, mixed>> */
    public function history(string $instanceId): array;

    public function connectionName(): ?string;
}
