<?php

declare(strict_types=1);

namespace Muni\Shared\Testing;

use Muni\Shared\Seguridad\PermisosHuerfanos;
use PHPUnit\Framework\Assert;

/**
 * Falla si existe un permiso (spatie/Shield) que ningún rol del seeder recibe:
 *
 *     use Muni\Shared\Testing\AssertNingunPermisoHuerfano;
 *
 *     uses(AssertNingunPermisoHuerfano::class);
 *
 *     it('todo permiso lo recibe algún rol', function () {
 *         $this->seed(RolesSeeder::class);
 *
 *         static::assertNingunPermisoHuerfano(excepciones: [
 *             // Sólo lo usa super_admin (Gate::before), a propósito.
 *             'ver_diagnostico_interno',
 *         ]);
 *     });
 *
 * La lista de excepciones es EXPLÍCITA y también se custodia: una excepción que
 * ya no existe o que ya recibe un rol hace fallar la aserción. Es un trait de
 * PHPUnit y no depende de Pest.
 */
trait AssertNingunPermisoHuerfano
{
    /**
     * @param  list<string>  $excepciones
     */
    protected static function assertNingunPermisoHuerfano(array $excepciones = [], ?string $guard = null): void
    {
        $problemas = PermisosHuerfanos::problemas($excepciones, $guard);

        Assert::assertSame([], $problemas, implode("\n", $problemas));
    }
}
