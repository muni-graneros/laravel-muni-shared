<?php

use Muni\Shared\Seguridad\PermisosBajoOctane;
use Muni\Shared\Testing\AssertPermisosNoSeQuedanPegados;
use PHPUnit\Framework\AssertionFailedError;

/**
 * `config/permission.php` estaba «copiado en 6 sistemas» y era engañoso: es el
 * config que publica `spatie/laravel-permission`, no código municipal. Medido:
 * los 7 sistemas que lo tienen coinciden en TODOS los valores, y solo UNO se
 * aparta del valor por omisión del vendor —`register_octane_reset_listener`,
 * `true` en los 7 contra `false` en spatie—.
 *
 * Por eso el archivo NO se mueve al paquete: traerlo obligaría a mantener acá el
 * esquema de configuración de spatie (las copias de 5 sistemas ya se quedaron
 * sin `models.team` y `models.default_model`, que spatie agregó después). Lo que
 * sí es municipal —esa línea— viaja como candado, que es version-proof.
 *
 * Qué pasa si se pierde: bajo Octane el registrador de permisos de spatie
 * conserva su caché en memoria entre peticiones del mismo worker. A quien se le
 * revoca un rol se lo sigue reconociendo hasta que el worker recicle.
 */
uses(AssertPermisosNoSeQuedanPegados::class)->in(__DIR__.'/PermisosBajoOctaneTest.php');

it('no encuentra nada que objetar cuando el flag está puesto', function () {
    config()->set('permission.register_octane_reset_listener', true);

    expect(PermisosBajoOctane::problemas(conOctane: true))->toBe([]);
});

it('detecta el flag apagado cuando el sistema corre sobre Octane', function (mixed $valor) {
    config()->set('permission.register_octane_reset_listener', $valor);

    expect(PermisosBajoOctane::problemas(conOctane: true))->toHaveCount(1)
        ->and(PermisosBajoOctane::problemas(conOctane: true)[0])
        ->toContain('register_octane_reset_listener');
})->with([
    'false' => [false],
    'ausente' => [null],
]);

it('no objeta nada en un sistema que no usa Octane', function () {
    config()->set('permission.register_octane_reset_listener', false);

    expect(PermisosBajoOctane::problemas(conOctane: false))->toBe([]);
});

it('no objeta nada donde spatie/permission ni siquiera está instalado', function () {
    config()->set('permission', null);

    expect(PermisosBajoOctane::problemas(conOctane: true))->toBe([]);
});

it('expone el aserto que cada sistema mete en su suite', function () {
    config()->set('permission.register_octane_reset_listener', true);

    static::assertPermisosNoSeQuedanPegados(conOctane: true);
});

it('el aserto falla cuando el flag se apaga', function () {
    config()->set('permission.register_octane_reset_listener', false);

    static::assertPermisosNoSeQuedanPegados(conOctane: true);
})->throws(AssertionFailedError::class);
