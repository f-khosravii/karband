<?php

declare(strict_types=1);

namespace Intl\AuthorizationLaravel\Bridge;

use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext as AuthorizationActorContext;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\Workflow\ValueObjects\ActorContext as WorkflowActorContext;
use Intl\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** Optional mapping from Workflow's existing authorization contract. */
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
