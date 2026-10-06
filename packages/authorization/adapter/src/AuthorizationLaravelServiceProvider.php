<?php

declare(strict_types=1);

namespace Karband\AuthorizationLaravel;

use Illuminate\Support\ServiceProvider;
use Karband\Authorization\AuthorizerInterface;

/** PrincipalProvider and ScopeChecker MUST be explicitly supplied by each service. */
final class AuthorizationLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthorizerInterface::class, SpatieAuthorizer::class);
        $this->app->bind(\Karband\Authorization\Contracts\ActorProviderInterface::class, \Karband\AuthorizationLaravel\Services\LaravelActorProvider::class);
        // No default principal, resource scope, WorkflowAuthorizer, or user permissions.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
