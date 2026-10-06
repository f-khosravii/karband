<?php

declare(strict_types=1);

namespace Karband\AuditLaravel\ValueObjects;

use Illuminate\Contracts\Auth\Authenticatable;
use Karband\Authorization\ActorContext;

final readonly class AuditPrincipal
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
