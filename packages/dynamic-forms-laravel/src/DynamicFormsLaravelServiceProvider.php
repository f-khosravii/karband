<?php

declare(strict_types=1);

namespace Intl\DynamicFormsLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Intl\DynamicForms\Contracts\RuleRegistry;
use Intl\DynamicForms\Engine\EmptyRuleRegistry;
use Intl\DynamicFormsLaravel\Services\FormStorage;

final class DynamicFormsLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/intl-dynamic-forms.php', 'intl-dynamic-forms');
        if (!$this->app->bound(RuleRegistry::class)) {
            $this->app->bind(RuleRegistry::class, EmptyRuleRegistry::class);
        }
        $this->app->singleton(FormStorage::class, fn ($app): FormStorage =>
            new FormStorage(DB::connection($app['config']->get('intl-dynamic-forms.connection'))));
        // Authentication, scope, permissions and custom rules are NEVER auto-granted.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/intl-dynamic-forms.php' => config_path('intl-dynamic-forms.php'),
        ], 'intl-dynamic-forms-config');
    }
}
