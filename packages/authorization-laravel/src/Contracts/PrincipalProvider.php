<?php

declare(strict_types=1);

namespace Intl\AuthorizationLaravel\Contracts;

use Intl\AuthorizationLaravel\Principal;

/** Host-authenticated user + selected role and agency. No default implementation. */
interface PrincipalProvider
{
    public function current(): Principal;
}
