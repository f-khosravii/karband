<?php

declare(strict_types=1);

namespace Intl\WorkflowLaravel\Services;

use Illuminate\Database\Connection;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** Share ONE database connection across definition, instance and audit writes. */
final readonly class WorkflowStorage
{
    public function __construct(public Connection $connection)
    {
    }

    public function snapshot(object $row, WorkflowDefinition $definition): WorkflowInstance
    {
        return new WorkflowInstance(
            id: (string) $row->id,
            resourceType: (string) $row->resource_type,
            resourceId: (string) $row->resource_id,
            workflowId: $definition->id,
            workflowVersion: $definition->version,
            currentState: (string) $row->current_state,
            revision: (int) $row->revision,
        );
    }
}
