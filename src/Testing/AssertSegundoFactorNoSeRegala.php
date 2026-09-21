<?php

declare(strict_types=1);

namespace Muni\Shared\Testing;

use Muni\Shared\Seguridad\SegundoFactorEnProduccion;
use PHPUnit\Framework\Assert;

/**
 * Lo único que cada sistema adoptante tiene que escribir para que la advertencia
 * que su `config/mfa.php` trae en un comentario pase a hacerse cumplir:
 *
 *     use Muni\Shared\Testing\AssertSegundoFactorNoSeRegala;
 *
 *     uses(AssertSegundoFactorNoSeRegala::class);
 *
 *     it('en producción el segundo factor ni se apaga ni se regala', function () {
 *         static::assertSegundoFactorNoSeRegala(enProduccion: true);
 *     });
 *
 * `enProduccion: true` es lo normal en una prueba: la suite corre con
 * `APP_ENV=testing` y lo que se quiere ejercitar es qué pasaría con la
 * configuración de producción. Sin el argumento, mira el entorno real.
 *
 * Es un trait de PHPUnit y no depende de Pest: `atencionvecino` corre PHPUnit 11
 * y lo puede usar igual desde su `TestCase`.
 */
trait AssertSegundoFactorNoSeRegala
{
    protected static function assertSegundoFactorNoSeRegala(?bool $enProduccion = null): void
    {
        $problemas = SegundoFactorEnProduccion::problemas($enProduccion);

        Assert::assertSame([], $problemas, implode("\n", $problemas));
    }
}
