<?php

use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Bloqueos;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;
use Muni\Shared\Tests\TestCase;

/**
 * `titular_id` es varchar(64) (ver la migración
 * 2026_08_24_000001_admitir_titulares_con_clave_no_numerica), pero
 * `Bloqueos::deEsteTitular()` lo consultaba con `$titular->getKey()` tal cual:
 * para un titular con clave autoincremental eso es un `int` de PHP, y el
 * conector lo liga como `PDO::PARAM_INT`.
 *
 * MariaDB, comparando una columna string contra un entero ligado, solo usa la
 * primera columna del índice compuesto `privacidad_bloqueos_titular_idx`
 * (`titular_type`) y recorre en «Using where» todas las filas de ese tipo para
 * decidir cuáles calzan por `titular_id` —el índice deja de acotar por la
 * segunda columna—. Ligando el mismo valor como string, el `ref` usa las DOS
 * columnas y el plan pasa de recorrer cientos de filas a leer una sola.
 *
 * Se salta sin MariaDB a mano por lo mismo que el resto de esta carpeta: el
 * comportamiento es del optimizador del motor real, SQLite no lo reproduce.
 */
beforeEach(function () {
    if (! TestCase::hayMariaDb()) {
        test()->markTestSkipped('Sin MariaDB: exportar MUNI_MARIADB_HOST (ver TestCase::hayMariaDb()).');
    }

    config(['privacidad.sistema' => 'discapacidad']);
});

it('la consulta de un bloqueo vigente usa las dos columnas del índice, no solo titular_type', function () {
    $titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    // Relleno con el MISMO titular_type y otras 500 claves: si la consulta
    // solo acota por titular_type, el motor tiene que revisarlas todas.
    $relleno = collect(range(1000, 1499))->map(fn (int $i): array => [
        'sistema' => 'discapacidad',
        'titular_type' => $titular->getMorphClass(),
        'titular_id' => (string) $i,
        'motivo' => 'Relleno para forzar el plan de consulta.',
        'desde' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->all();
    DB::table('privacidad_bloqueos')->insert($relleno);

    app(Bloqueos::class)->bloquear($titular, null, 'Oposición acogida');

    // El mismo método privado que usan vigente(), impideCorregir() y
    // sistemasConBloqueoVigente(): se llama por reflexión para comprobar el
    // SQL y los bindings que realmente arma el módulo, no una reconstrucción
    // aparte que probaría otra cosa.
    $metodo = new ReflectionMethod(Bloqueos::class, 'deEsteTitular');
    $query = $metodo->invoke(app(Bloqueos::class), $titular);

    // Sin esto el optimizador arranca con estadísticas de tabla vacía —la
    // tabla se acaba de poblar en esta misma transacción— y descarta CUALQUIER
    // índice a favor de un barrido completo, lo que taparía la diferencia que
    // este test busca medir.
    DB::statement('ANALYZE TABLE privacidad_bloqueos');

    $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())[0];

    // No se fija en CUÁL de los dos índices que cubren (titular_type,
    // titular_id) elige el optimizador —hay uno automático de
    // `nullableMorphs()` además del explícito—, sino en si `titular_id`
    // participó de la búsqueda. Ligado como entero, MariaDB no puede
    // comparar la columna varchar sin convertirla fila por fila: descarta
    // los dos índices y barre la tabla entera («type» ALL, «key» null).
    // Ligado como string, entra por «ref» con las DOS columnas resueltas
    // («const,const») y lee una sola fila en vez de las 501 de relleno.
    expect($plan->type)->not->toBe('ALL')
        ->and($plan->key)->not->toBeNull()
        ->and($plan->ref)->toBe('const,const')
        ->and((int) $plan->rows)->toBeLessThan(5);
});
