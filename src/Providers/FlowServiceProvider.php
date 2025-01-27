<?php

namespace MrNewport\LaravelFlow\Providers;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use MrNewport\LaravelFlow\Commands\DefineStepCommand;

class FlowServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('mrnewport-laravel-flow')
            ->hasConfigFile('flow')
            ->hasMigrations(['2025_01_01_000000_create_flow_tables'])
            ->hasCommands([DefineStepCommand::class])
            ->hasViews();
    }

    public function boot()
    {
        parent::boot();

        $this->publishes([
            __DIR__.'/config/flow.php' => config_path('flow.php'),
        ], 'flow-config');

        $this->publishes([
            __DIR__.'/../resources/stubs' => base_path('stubs/laravel-flow'),
        ], 'flow-stubs');
    }
}
