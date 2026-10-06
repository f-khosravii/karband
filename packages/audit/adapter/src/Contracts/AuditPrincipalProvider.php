<?php

declare(strict_types=1);

namespace Karband\AuditLaravel\Contracts;

use Karband\AuditLaravel\ValueObjects\AuditPrincipal;

interface AuditPrincipalProvider
{
    public function current(): AuditPrincipal;
}
