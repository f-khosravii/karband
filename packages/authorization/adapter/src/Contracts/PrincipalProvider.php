<?php

declare(strict_types=1);

namespace Karband\AuthorizationLaravel\Contracts;

use Karband\AuthorizationLaravel\Principal;

/** Host-authenticated user + selected role and agency. No default implementation. */
interface PrincipalProvider
{
    public function current(): Principal;
}
