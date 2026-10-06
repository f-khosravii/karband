<?php

declare(strict_types=1);

namespace Intl\AuthorizationLaravel;

use Illuminate\Support\ServiceProvider;
use Intl\Contracts\Authorization\AuthorizerInterface;

/** PrincipalProvider and ScopeChecker MUST be explicitly supplied by each service. */
final class AuthorizationLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthorizerInterface::class, SpatieAuthorizer::class);
        // No default principal, resource scope, WorkflowAuthorizer, or user permissions.
    }
}
