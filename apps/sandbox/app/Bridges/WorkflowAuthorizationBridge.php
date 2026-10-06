<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext as AuthorizationActorContext;
use Karband\Authorization\AuthorizerInterface;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\ValueObjects\ActorContext as WorkflowActorContext;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** Host composition bridge mapping Workflow authorization contract to Authorization capability. */
final readonly class WorkflowAuthorizationBridge implements WorkflowAuthorizer
{
    public function __construct(private AuthorizerInterface $authorization)
    {
    }

    public function authorizePublish(WorkflowDefinition $definition, WorkflowActorContext $actor): void
    {
        $this->authorize($actor, 'workflow.definition.publish', 'workflow.definition', $definition->id);
    }

    public function authorizeStart(
        WorkflowDefinition $definition,
        string $resourceType,
        string $resourceId,
        WorkflowActorContext $actor,
    ): void {
        $this->authorize($actor, 'workflow.instance.start', $resourceType, $resourceId);
    }

    public function authorizeAction(WorkflowInstance $instance, string $action, WorkflowActorContext $actor): void
    {
        $this->authorize($actor, 'workflow.action.' . $action, $instance->resourceType, $instance->resourceId);
    }

    public function authorizeView(WorkflowInstance $instance, WorkflowActorContext $actor): void
    {
        $this->authorize($actor, 'workflow.instance.view', $instance->resourceType, $instance->resourceId);
    }

    private function authorize(WorkflowActorContext $actor, string $ability, string $resourceType, string $resourceId): void
    {
        $this->authorization->authorize(
            new AuthorizationActorContext($actor->actorId, $actor->role, $actor->organizationId),
            new AccessRequest($ability, $resourceType, $resourceId),
        );
    }
}
