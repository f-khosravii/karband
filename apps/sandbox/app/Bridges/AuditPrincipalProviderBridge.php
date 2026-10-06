<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\AuditLaravel\Contracts\AuditPrincipalProvider;
use Karband\AuditLaravel\ValueObjects\AuditPrincipal;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;

final readonly class AuditPrincipalProviderBridge implements AuditPrincipalProvider
{
    public function __construct(
        private PrincipalProvider $principals,
    ) {}

    public function current(): AuditPrincipal
    {
        $p = $this->principals->current();
        return new AuditPrincipal($p->user, $p->actor, $p->guard);
    }
}
