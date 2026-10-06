<?php

declare(strict_types=1);

namespace Karband\WorkflowLaravel\ValueObjects;

/** Immutable snapshot. Does not grant access; every command re-authorizes. */
final readonly class WorkflowInstance
{
    public function __construct(
        public string $id,
        public string $resourceType,
        public string $resourceId,
        public string $workflowId,
        public int $workflowVersion,
        public string $currentState,
        public int $revision,
    ) {
    }
}
