<?php

declare(strict_types=1);

namespace Intl\ExternalAuthLaravel\Contracts;

use Intl\AuthorizationLaravel\Principal;
use Intl\ExternalAuth\ValueObjects\ExternalIdentity;

/**
 * Host microservice maps a trusted ExternalIdentity to a local Principal.
 * The microservice remains authoritative for local user, role, and permissions.
 */
interface ExternalPrincipalResolver
{
    public function resolve(ExternalIdentity $identity): Principal;
}
