<?php

use Muni\Shared\Testing\AssertEnvExampleCompleto;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * El trait que cada adoptante suma a su propio EnvExampleCompletoTest.php.
 * Se prueba con las mismas fixtures que ContratoDeEnvExample, pasando las
 * rutas a mano: sin argumentos usaría `config_path()`/`base_path()`, que acá
 * apuntarían al esqueleto vacío de Testbench y no a nada interesante.
 */
uses(AssertEnvExampleCompleto::class)->in(__DIR__.'/AssertEnvExampleCompletoTest.php');

it('pasa cuando .env.example documenta todo lo que config/ usa', function () {
    $this->assertEnvExampleCompleto(
        __DIR__.'/Fixtures/ConfigDeMentira',
        __DIR__.'/Fixtures/env-example-completo-de-mentira/.env.example',
    );
});

it('falla, con el detalle de la clave, cuando algo queda sin documentar', function () {
    expect(fn () => $this->assertEnvExampleCompleto(__DIR__.'/Fixtures/ConfigDeMentira', '/no/existe/.env.example'))
        ->toThrow(ExpectationFailedException::class, 'MAESTRO_URL');
});
