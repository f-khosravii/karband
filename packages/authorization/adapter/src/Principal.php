<?php

declare(strict_types=1);

namespace Karband\AuthorizationLaravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Karband\Authorization\ActorContext;

/** Principal provider must derive this from the *current* Laravel guard/session. */
final readonly class Principal
{
    public function __construct(
        public Authenticatable $user,
        public ActorContext $actor,
        public string $guard,
    ) {
        if (trim($guard) === '') {
            throw new \InvalidArgumentException('Guard must not be empty.');
        }
    }
}
