<?php

declare(strict_types=1);

namespace Karband\WorkflowLaravel\Support;

use Karband\Workflow\ValueObjects\ActorContext;
use Karband\WorkflowLaravel\Contracts\TrustedFactProvider;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** No trusted facts by default; guards needing facts must fail closed. */
final class EmptyTrustedFacts implements TrustedFactProvider
{
    public function forAction(WorkflowInstance $instance, string $action, ActorContext $actor): array
    {
        return [];
    }
}
