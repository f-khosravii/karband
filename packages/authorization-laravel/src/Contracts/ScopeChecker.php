<?php

declare(strict_types=1);

namespace Intl\AuthorizationLaravel\Contracts;

use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;

/** Host checks trusted resource ownership and optional Laravel Policies. */
interface ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool;
}
