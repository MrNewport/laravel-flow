<?php

namespace MrNewport\LaravelFlow\Providers;

use Illuminate\Support\ServiceProvider;
use MrNewport\LaravelFlow\Commands\DefineStepCommand;
use MrNewport\LaravelFlow\Commands\MigrateMorphMapCommand;

class FlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flow.php', 'flow');
    }

    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-flow');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-flow');
        if ($this->app->runningInConsole()) {
            $this->commands([DefineStepCommand::class, MigrateMorphMapCommand::class]);
        }

        $this->publishes([
            __DIR__.'/../config/flow.php' => config_path('flow.php'),
        ], 'flow-config');
        $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/laravel-flow')], 'flow-translations');

    }
}
