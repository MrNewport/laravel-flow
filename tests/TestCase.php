<?php

namespace MrNewport\LaravelFlow\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use MrNewport\LaravelFlow\Providers\FlowServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            FlowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default','testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        // Load the package migrations so the tables are created
        $this->loadMigrationsFrom(__DIR__.'/../src/database/migrations');
    }
}
