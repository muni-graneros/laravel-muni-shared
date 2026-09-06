<?php

declare(strict_types=1);

namespace Muni\Shared\Testing;

use Muni\Shared\Seguridad\PermisosBajoOctane;
use PHPUnit\Framework\Assert;

/**
 * Lo único que cada sistema adoptante tiene que escribir para custodiar la única
 * línea municipal de su `config/permission.php`:
 *
 *     use Muni\Shared\Testing\AssertPermisosNoSeQuedanPegados;
 *
 *     uses(AssertPermisosNoSeQuedanPegados::class);
 *
 *     it('los permisos no se quedan pegados entre peticiones de Octane', function () {
 *         static::assertPermisosNoSeQuedanPegados();
 *     });
 *
 * Sin argumentos detecta sola si el sistema corre sobre Octane. Es un trait de
 * PHPUnit y no depende de Pest: `atencionvecino` corre PHPUnit 11 y lo puede
 * usar igual desde un `TestCase` propio.
 */
trait AssertPermisosNoSeQuedanPegados
{
    protected static function assertPermisosNoSeQuedanPegados(?bool $conOctane = null): void
    {
        Assert::assertSame(
            [],
            PermisosBajoOctane::problemas($conOctane),
            implode("\n", PermisosBajoOctane::problemas($conOctane)),
        );
    }
}
