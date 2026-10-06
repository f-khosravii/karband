<?php

declare(strict_types=1);

namespace Intl\WorkflowLaravel\Support;

use Intl\Workflow\ValueObjects\ActorContext;
use Intl\WorkflowLaravel\Contracts\TrustedFactProvider;
use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** No trusted facts by default; guards needing facts must fail closed. */
final class EmptyTrustedFacts implements TrustedFactProvider
{
    public function forAction(WorkflowInstance $instance, string $action, ActorContext $actor): array
    {
        return [];
    }
}
