<?php

declare(strict_types=1);

namespace Intl\Contracts\Authorization;

/** Fail-closed: returns only on authorization success; throws on denial. */
interface AuthorizerInterface
{
    /** @throws AuthorizationDenied */
    public function authorize(ActorContext $actor, AccessRequest $request): void;
}
