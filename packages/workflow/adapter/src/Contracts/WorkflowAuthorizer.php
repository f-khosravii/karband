<?php

declare(strict_types=1);

namespace Karband\WorkflowLaravel\Contracts;

use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\ValueObjects\ActorContext;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** Every method must throw on denial. Missing bindings must fail closed. */
interface WorkflowAuthorizer
{
    public function authorizePublish(WorkflowDefinition $definition, ActorContext $actor): void;

    public function authorizeStart(
        WorkflowDefinition $definition,
        string $resourceType,
        string $resourceId,
        ActorContext $actor,
    ): void;

    public function authorizeAction(WorkflowInstance $instance, string $action, ActorContext $actor): void;

    public function authorizeView(WorkflowInstance $instance, ActorContext $actor): void;
}
