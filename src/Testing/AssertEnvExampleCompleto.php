<?php

namespace Muni\Shared\Testing;

use PHPUnit\Framework\Assert;

/**
 * Lo único que cada sistema adoptante tiene que escribir para sumarse al
 * chequeo de `ContratoDeEnvExample`:
 *
 *     use Muni\Shared\Testing\AssertEnvExampleCompleto;
 *
 *     uses(AssertEnvExampleCompleto::class);
 *
 *     it('.env.example documenta todo lo que config/ lee', function () {
 *         static::assertEnvExampleCompleto();
 *     });
 *
 * Sin argumentos usa `config_path()` y `base_path('.env.example')` del
 * sistema que corre el test —los mismos que Laravel usa en producción—; se
 * pueden pasar rutas propias para un caso puntual o una prueba del propio
 * paquete.
 */
trait AssertEnvExampleCompleto
{
    protected static function assertEnvExampleCompleto(?string $rutaConfig = null, ?string $rutaEnvExample = null): void
    {
        $rutaConfig ??= function_exists('config_path') ? config_path() : base_path('config');
        $rutaEnvExample ??= base_path('.env.example');

        $faltantes = ContratoDeEnvExample::clavesFaltantes($rutaConfig, $rutaEnvExample);

        Assert::assertSame(
            [],
            $faltantes,
            sprintf(
                '%d clave(s) que config/ lee con env() no están en %s (ni comentadas): %s',
                count($faltantes),
                $rutaEnvExample,
                implode(', ', $faltantes),
            ),
        );
    }
}
