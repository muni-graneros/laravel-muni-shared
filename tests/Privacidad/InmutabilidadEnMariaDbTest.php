<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Tests\TestCase;

/**
 * El mismo intento de alteración, contra el motor real de producción.
 *
 * Lo que se prueba acá no es la lógica —esa ya la cubre
 * InmutabilidadEnBaseDeDatosTest— sino que el SQL de MySQL/MariaDB existe, se
 * instala y rechaza. Un `SIGNAL` mal escrito no se nota corriendo en SQLite, y
 * el agujero quedaría abierto justo donde vive la evidencia de verdad.
 *
 * Se saltan solas si no hay motor a mano; ver `TestCase::hayMariaDb()` para el
 * contenedor desechable de una línea.
 */
beforeEach(function () {
    if (! TestCase::hayMariaDb()) {
        test()->markTestSkipped('Sin MariaDB: exportar MUNI_MARIADB_HOST (ver TestCase::hayMariaDb()).');
    }

    config(['privacidad.sistema' => 'discapacidad']);
});

it('MariaDB rechaza alterar el contenido de un texto publicado por query builder', function () {
    app(Textos::class)->publicar('aviso_recoleccion', 'Original');

    expect(fn () => TextoInformativo::query()->update(['contenido' => 'ALTERADO SIN EXCEPCION']))
        ->toThrow(QueryException::class, 'privacidad_textos es inmutable')
        ->and(TextoInformativo::sole()->contenido)->toBe('Original');
});

it('MariaDB rechaza alterar la bitácora por query builder', function () {
    app(RegistroDeEvidencia::class)->registrar('prueba.evento', ['campo' => 'valor']);

    expect(fn () => EntradaBitacora::query()->update(['evento' => 'ALTERADO']))
        ->toThrow(QueryException::class, 'privacidad_bitacora es append-only')
        ->and(EntradaBitacora::sole()->evento)->toBe('prueba.evento')
        ->and(fn () => DB::table('privacidad_bitacora')->update(['datos' => null]))
        ->toThrow(QueryException::class);
});

it('MariaDB deja pasar las mutaciones sancionadas del módulo', function () {
    $servicio = app(Textos::class);
    $primera = $servicio->publicar('aviso_recoleccion', 'Texto viejo');

    $servicio->publicar('aviso_recoleccion', 'Texto nuevo');

    app(RegistroDeEvidencia::class)->registrar('prueba.evento', []);
    DB::table('privacidad_bitacora')->update(['user_id' => null, 'titular_id' => null, 'titular_ref' => 'x']);

    expect($primera->fresh()->vigente_hasta)->not->toBeNull()
        ->and($primera->fresh()->contenido)->toBe('Texto viejo')
        ->and(EntradaBitacora::sole()->titular_ref)->toBe('x');
});
