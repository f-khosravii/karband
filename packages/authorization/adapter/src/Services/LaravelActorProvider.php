<?php

declare(strict_types=1);

namespace Karband\AuthorizationLaravel\Services;

use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;

final readonly class LaravelActorProvider implements ActorProviderInterface
{
    public function __construct(
        private PrincipalProvider $principals,
    ) {}

    public function currentActor(): ActorContext
    {
        try {
            return $this->principals->current()->actor;
        } catch (\Throwable $e) {
            throw new AuthorizationDenied('not_authenticated', previous: $e);
        }
    }
}
