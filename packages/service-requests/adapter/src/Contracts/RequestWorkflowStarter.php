<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

use Karband\ServiceRequestsLaravel\ValueObjects\RequestWorkflowInstance;

/** Port for atomically starting a pinned workflow instance for a service request. */
interface RequestWorkflowStarter
{
    public function start(string $serviceCode, string $resourceType, string $resourceId): RequestWorkflowInstance;
}
