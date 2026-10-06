<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\ValueObjects;

final readonly class RequestWorkflowInstance
{
    public function __construct(
        public string $id,
        public string $workflowId,
        public int $workflowVersion,
        public string $currentState,
        public int $revision = 0,
        public int $definitionRecordId = 0,
    ) {}
}
