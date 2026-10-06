<?php

declare(strict_types=1);

namespace Karband\Authorization\Contracts;

use Karband\Authorization\ActorContext;

/**
 * Interface for providing the currently authenticated actor context.
 */
interface ActorProviderInterface
{
    public function currentActor(): ActorContext;
}
