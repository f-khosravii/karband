<?php

declare(strict_types=1);

namespace Karband\ExternalAuthLaravel\Contracts;

use Karband\ExternalAuth\ValueObjects\ExternalIdentity;

/**
 * Host microservice maps a trusted ExternalIdentity to a local Principal / Actor object.
 * The microservice remains authoritative for local user, role, and permissions.
 */
interface ExternalPrincipalResolver
{
    public function resolve(ExternalIdentity $identity): mixed;
}
