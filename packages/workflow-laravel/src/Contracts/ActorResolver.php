<?php

declare(strict_types=1);

namespace Intl\WorkflowLaravel\Contracts;

use Intl\Workflow\ValueObjects\ActorContext;

/** Host must authenticate and derive this context; never trust an HTTP role field. */
interface ActorResolver
{
    public function resolve(): ActorContext;
}
