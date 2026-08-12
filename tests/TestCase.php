<?php

namespace Muni\Shared\Tests;

use Muni\Shared\MuniSharedServiceProvider;
use Orchestra\Testbench\TestCase as Base;

/**
 * Base de las pruebas del paquete.
 *
 * Registra el service provider, que es lo que hace que las pruebas ejerciten lo
 * mismo que un sistema real: el transporte de correo y los comandos existen
 * porque el provider los registra, no porque la prueba los arme a mano.
 */
abstract class TestCase extends Base
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MuniSharedServiceProvider::class];
    }
}
