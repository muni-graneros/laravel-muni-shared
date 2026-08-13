<?php

namespace Muni\Shared\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Muni\Shared\MuniSharedServiceProvider;
use Orchestra\Testbench\TestCase as Base;

/**
 * Base de las pruebas del paquete.
 *
 * Registra el service provider, que es lo que hace que las pruebas ejerciten lo
 * mismo que un sistema real: el transporte de correo, los comandos y las
 * migraciones del módulo de privacidad existen porque el provider los registra,
 * no porque la prueba los arme a mano.
 */
abstract class TestCase extends Base
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MuniSharedServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Privacidad/Fixtures/migrations');
    }
}
