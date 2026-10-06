<?php

declare(strict_types=1);

namespace Karband\AuthorizationLaravel\Contracts;

use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;

/** Host checks trusted resource ownership and optional Laravel Policies. */
interface ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool;
}
