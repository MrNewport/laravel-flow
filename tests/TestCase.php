<?php

namespace MrNewport\LaravelFlow\Tests;

use MrNewport\LaravelFlow\Providers\FlowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

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
        $app['config']->set('database.default', 'testing');
        if (getenv('FLOW_ALL_MYSQL_TEST_DATABASE') === 'flow_all_test') {
            $configuration = [
                'driver' => 'mysql', 'host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
                'port' => getenv('MYSQL_TEST_PORT') ?: 3306, 'unix_socket' => getenv('MYSQL_TEST_SOCKET') ?: '',
                'database' => 'flow_all_test', 'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root',
                'password' => getenv('MYSQL_TEST_PASSWORD') ?: '', 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00',
            ];
            $app['config']->set('database.connections.testing', $configuration);
            // This opt-in database is disposable. Reset before package migrations for each test.
            $connection = $app['db']->connection('testing');
            if ($connection->getDatabaseName() !== 'flow_all_test') {
                throw new \LogicException('Full MySQL tests require the dedicated flow_all_test database.');
            }
            $connection->getSchemaBuilder()->dropAllTables();

            return;
        }
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        // Load the package migrations so the tables are created
        $this->loadMigrationsFrom(__DIR__.'/../src/database/migrations');
    }
}
