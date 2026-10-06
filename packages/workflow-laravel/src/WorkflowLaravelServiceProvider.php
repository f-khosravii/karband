<?php

declare(strict_types=1);

namespace Intl\WorkflowLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Intl\Workflow\Engine\StateMachine;
use Intl\WorkflowLaravel\Contracts\TrustedFactProvider;
use Intl\WorkflowLaravel\Support\EmptyTrustedFacts;
use Intl\WorkflowLaravel\Services\WorkflowStorage;

final class WorkflowLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/intl-workflow.php', 'intl-workflow');

        $this->app->singleton(WorkflowStorage::class, function ($app): WorkflowStorage {
            return new WorkflowStorage(DB::connection($app['config']->get('intl-workflow.connection')));
        });

        $this->app->singleton(StateMachine::class, function ($app): StateMachine {
            $guards = [];
            foreach ($app['config']->get('intl-workflow.guards', []) as $key => $class) {
                if (!is_string($key) || !is_string($class) || $class === '') {
                    throw new \InvalidArgumentException('Each workflow guard needs a string key and class name.');
                }
                $guards[$key] = $app->make($class);
            }
            return new StateMachine($guards);
        });

        if (! $this->app->bound(TrustedFactProvider::class)) {
            $this->app->bind(TrustedFactProvider::class, EmptyTrustedFacts::class);
        }
        // Deliberately NO default ActorResolver or WorkflowAuthorizer binding.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/intl-workflow.php' => config_path('intl-workflow.php'),
        ], 'intl-workflow-config');
    }
}
