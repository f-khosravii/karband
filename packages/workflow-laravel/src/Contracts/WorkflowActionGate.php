<?php

declare(strict_types=1);

namespace Intl\WorkflowLaravel\Contracts;

use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** Optional integration boundary, enforced when the ServiceRequests host registers it. */
interface WorkflowActionGate
{
    public function assertWorkflowAllowed(WorkflowInstance $instance, string $action): void;
}
