<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\Workflow\ValueObjects\ActorContext;
use Karband\WorkflowLaravel\Contracts\ActorResolver;

/** Host composition bridge: resolves workflow actors from authorization PrincipalProvider. */
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
