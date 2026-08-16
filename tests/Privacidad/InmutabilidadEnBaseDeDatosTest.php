<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\InmutabilidadEnBaseDeDatos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

/**
 * El agujero que estas pruebas cierran: los guardias `updating` de los modelos
 * no se disparan en las escrituras masivas del query builder, así que
 * `TextoInformativo::query()->update([...])` alteraba la evidencia sin
 * excepción y sin dejar rastro. Cada `it` de acá abajo INTENTA la alteración de
 * verdad; no comprueba que exista el trigger, comprueba que el motor la rechaza.
 */
beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
});

it('el motor rechaza alterar el contenido de un texto publicado, aunque se entre por query builder', function () {
    app(Textos::class)->publicar('aviso_recoleccion', 'Original');

    expect(fn () => TextoInformativo::query()->update(['contenido' => 'ALTERADO SIN EXCEPCION']))
        ->toThrow(QueryException::class)
        ->and(TextoInformativo::sole()->contenido)->toBe('Original');
});

it('el motor rechaza alterar cada columna probatoria del texto', function (string $columna, mixed $valor) {
    app(Textos::class)->publicar('aviso_recoleccion', 'Original');
    $antes = TextoInformativo::sole()->getAttributes();

    expect(fn () => DB::table('privacidad_textos')->update([$columna => $valor]))
        ->toThrow(QueryException::class)
        ->and(TextoInformativo::sole()->getAttributes())->toBe($antes);
})->with([
    ['contenido', 'otro texto'],
    ['hash', str_repeat('0', 64)],
    ['sistema', 'licencias'],
    ['codigo', 'otro_aviso'],
    ['version', 99],
    ['vigente_desde', '2000-01-01 00:00:00'],
]);

it('el motor rechaza alterar cada columna probatoria de la bitácora', function (string $columna, mixed $valor) {
    app(RegistroDeEvidencia::class)->registrar('prueba.evento', ['campo' => 'valor']);
    $antes = EntradaBitacora::sole()->getAttributes();

    expect(fn () => DB::table('privacidad_bitacora')->update([$columna => $valor]))
        ->toThrow(QueryException::class)
        ->and(EntradaBitacora::sole()->getAttributes())->toBe($antes);
})->with([
    ['evento', 'otro.evento'],
    ['datos', '{"campo":"otro"}'],
    ['ocurrido_en', '2000-01-01 00:00:00'],
    ['sistema', 'licencias'],
]);

it('rechaza también vaciar una columna probatoria a null', function () {
    app(RegistroDeEvidencia::class)->registrar('prueba.evento', ['campo' => 'valor']);

    // Con `=` a secas la comparación contra NULL da NULL y el trigger dejaría
    // pasar esto. Por eso el SQL usa `IS NOT` / `<=>`.
    expect(fn () => DB::table('privacidad_bitacora')->update(['datos' => null]))
        ->toThrow(QueryException::class)
        ->and(EntradaBitacora::sole()->datos)->toBe(['campo' => 'valor']);
});

it('rechaza llenar una columna probatoria que estaba en null', function () {
    DB::table('privacidad_bitacora')->insert([
        'sistema' => 'discapacidad',
        'evento' => 'prueba.evento',
        'datos' => null,
        'ocurrido_en' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(EntradaBitacora::sole()->datos)->toBeNull()
        ->and(fn () => DB::table('privacidad_bitacora')->update(['datos' => '{"inventado":true}']))
        ->toThrow(QueryException::class);
});

it('el mensaje del motor dice de qué tabla se trata', function () {
    app(Textos::class)->publicar('aviso_recoleccion', 'Original');

    expect(fn () => TextoInformativo::query()->update(['contenido' => 'x']))
        ->toThrow(QueryException::class, 'privacidad_textos es inmutable');
});

// --- Las mutaciones sancionadas del propio módulo tienen que seguir pasando ---

it('publicar una versión nueva sigue pudiendo cerrar la vigencia de la anterior', function () {
    $servicio = app(Textos::class);
    $primera = $servicio->publicar('aviso_recoleccion', 'Texto viejo');

    $segunda = $servicio->publicar('aviso_recoleccion', 'Texto nuevo');

    expect($primera->fresh()->vigente_hasta)->not->toBeNull()
        ->and($primera->fresh()->contenido)->toBe('Texto viejo')
        ->and($segunda->version)->toBe(2);
});

it('desvincular sigue pudiendo cortar el vínculo con el titular en la bitácora', function () {
    $persona = PersonaDePrueba::create(['nombre' => 'Ana', 'documento' => '1-9']);

    // La historia ocurre antes: una entrada del mismo segundo cae en la ventana
    // de anonimización y queda huérfana sin referencia de grupo, a propósito.
    $this->travelTo(now()->subDays(3));
    app(RegistroDeEvidencia::class)->registrar('prueba.evento', ['campo' => 'valor'], $persona);
    $this->travelBack();

    $resultado = app(Bitacora::class)->desvincular($persona);

    $entrada = EntradaBitacora::query()->where('evento', 'prueba.evento')->sole();

    expect($resultado->filas)->toBeGreaterThan(0)
        ->and($entrada->titular_id)->toBeNull()
        ->and($entrada->titular_ref)->not->toBeNull()
        // Lo que la ley obliga a conservar sobrevivió al barrido.
        ->and($entrada->evento)->toBe('prueba.evento')
        ->and($entrada->datos)->toBe(['campo' => 'valor']);
});

it('anular el user_id de una constancia sigue permitido', function () {
    // Es lo que hace Bitacora::registrarConstancia() por query builder.
    app(RegistroDeEvidencia::class)->registrar('prueba.evento', []);

    DB::table('privacidad_bitacora')->update(['user_id' => null]);

    expect(EntradaBitacora::sole()->user_id)->toBeNull();
});

it('escribir evidencia nueva sigue funcionando con el guardia puesto', function () {
    app(RegistroDeEvidencia::class)->registrar('uno', []);
    app(RegistroDeEvidencia::class)->registrar('dos', []);

    expect(EntradaBitacora::count())->toBe(2);
});

// --- Lo que el guardia NO cierra, escrito para que nadie lo lea de más ---

it('deja constancia de que el borrado por query builder sigue abierto', function () {
    app(RegistroDeEvidencia::class)->registrar('prueba.evento', []);

    // NO hay trigger BEFORE DELETE, y es una decisión documentada en
    // InmutabilidadEnBaseDeDatos: en SQLite se dispararía con el `delete from`
    // que compila `truncate`, rompiendo la suite de todo adoptante que use
    // DatabaseTruncation, y en MySQL `TRUNCATE TABLE` no dispara triggers. El
    // borrado se cierra con permisos del motor, no con esquema. Esta prueba
    // existe para que el día que alguien agregue el trigger de DELETE, falle y
    // lea el porqué.
    EntradaBitacora::query()->delete();

    expect(EntradaBitacora::count())->toBe(0);
});

it('en un driver sin SQL propio no promete protección', function () {
    expect(InmutabilidadEnBaseDeDatos::soporta('sqlite'))->toBeTrue()
        ->and(InmutabilidadEnBaseDeDatos::soporta('mysql'))->toBeTrue()
        ->and(InmutabilidadEnBaseDeDatos::soporta('mariadb'))->toBeTrue()
        ->and(InmutabilidadEnBaseDeDatos::soporta('pgsql'))->toBeFalse();
});
