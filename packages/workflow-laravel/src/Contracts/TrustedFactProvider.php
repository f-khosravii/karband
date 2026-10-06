<?php

declare(strict_types=1);

namespace Intl\WorkflowLaravel\Contracts;

use Intl\Workflow\ValueObjects\ActorContext;
use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** Compute read-only facts using the host's trusted repositories, not request payload. */
interface TrustedFactProvider
{
    /** @return array<string, mixed> */
    public function forAction(WorkflowInstance $instance, string $action, ActorContext $actor): array;
}
