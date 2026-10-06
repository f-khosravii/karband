<?php

declare(strict_types=1);

namespace Karband\DynamicFormsLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\DynamicForms\Contracts\RuleRegistry;
use Karband\DynamicForms\Engine\EmptyRuleRegistry;
use Karband\DynamicFormsLaravel\Services\FormStorage;

final class DynamicFormsLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dynamic-forms.php', 'dynamic-forms');
        if (!$this->app->bound(RuleRegistry::class)) {
            $this->app->bind(RuleRegistry::class, EmptyRuleRegistry::class);
        }
        $this->app->singleton(FormStorage::class, fn ($app): FormStorage =>
            new FormStorage(DB::connection($app['config']->get('dynamic-forms.connection'))));
        // Authentication, scope, permissions and custom rules are NEVER auto-granted.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/dynamic-forms.php' => config_path('dynamic-forms.php'),
        ], 'dynamic-forms-config');
    }
}
