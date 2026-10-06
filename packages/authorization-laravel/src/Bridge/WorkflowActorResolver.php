<?php

declare(strict_types=1);

namespace Intl\AuthorizationLaravel\Bridge;

use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\Workflow\ValueObjects\ActorContext;
use Intl\WorkflowLaravel\Contracts\ActorResolver;

/** Optional bridge. Bind ActorResolver in host only when using Workflow. */
final readonly class WorkflowActorResolver implements ActorResolver
{
    public function __construct(private PrincipalProvider $principals)
    {
    }

    public function resolve(): ActorContext
    {
        $actor = $this->principals->current()->actor;
        return new ActorContext($actor->id, $actor->role, $actor->organizationId);
    }
}
