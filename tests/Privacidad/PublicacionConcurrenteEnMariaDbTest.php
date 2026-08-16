<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;
use Muni\Shared\Privacidad\PublicacionEnConflicto;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Tests\TestCase;

/**
 * La carrera de `Textos::publicar()` con DOS CONEXIONES DE VERDAD.
 *
 * PublicacionConcurrenteTest simula al rival escribiendo desde el evento
 * `creating`, o sea en la misma conexión y dentro de la misma transacción. Eso
 * reproduce el choque de unicidad y nada más, y esa carencia escondió el defecto
 * que estas pruebas cubren: MariaDB usa REPEATABLE READ, así que cuando
 * `publicar()` corre dentro de la transacción de quien llama, una lectura común
 * devuelve siempre el snapshot inicial. El «rival» de la misma conexión sí se ve
 * —una transacción siempre se ve a sí misma—, y por eso la suite entera estuvo
 * verde sobre un reintento que en producción no podía resolver nada.
 *
 * Acá el rival es otro proceso a todos los efectos: su propio PDO, su propio
 * commit. Y el publicador corre dentro de una transacción abierta a mano, que es
 * el modo del adoptante que envuelve «publicar el aviso y anotar el acuerdo».
 *
 * Las filas que se escriben acá se COMMITEAN de verdad —esa es la gracia—, así
 * que no se las lleva el rollback de RefreshDatabase: las borra el afterEach.
 */
beforeEach(function () {
    if (! TestCase::hayMariaDb()) {
        test()->markTestSkipped('Sin MariaDB no hay dos conexiones ni aislamiento que probar; ver TestCase::hayMariaDb().');
    }

    config(['privacidad.sistema' => 'discapacidad']);

    // Dos conexiones más contra la misma base. Copiar la configuración en vez de
    // reusar «testing» es lo que las hace independientes: cada nombre abre su
    // propio PDO.
    $configuracion = config('database.connections.testing');

    config([
        'database.connections.publicador' => $configuracion,
        'database.connections.rival' => $configuracion,
    ]);
});

afterEach(function () {
    if (! TestCase::hayMariaDb()) {
        return;
    }

    // Cada conexión suelta lo que tenga abierto antes de limpiar: un test que
    // falló a mitad de camino puede dejar una transacción y sus candados.
    foreach (['publicador', 'rival'] as $nombre) {
        $conexion = DB::connection($nombre);

        while ($conexion->transactionLevel() > 0) {
            $conexion->rollBack();
        }
    }

    DB::connection('rival')->table('privacidad_textos')->delete();

    config(['database.default' => 'testing']);

    DB::purge('publicador');
    DB::purge('rival');
});

/** Publica una versión desde la conexión del rival y la commitea al toque. */
function rivalPublica(Connection $rival, string $codigo, int $version, string $contenido): void
{
    $vigente = $rival->table('privacidad_textos')
        ->where('sistema', 'discapacidad')->where('codigo', $codigo)
        ->whereNull('vigente_hasta')->first();

    if ($vigente !== null) {
        $rival->table('privacidad_textos')->where('id', $vigente->id)->update(['vigente_hasta' => now()]);
    }

    $rival->table('privacidad_textos')->insert([
        'sistema' => 'discapacidad',
        'codigo' => $codigo,
        'version' => $version,
        'contenido' => $contenido,
        'hash' => hash('sha256', $contenido),
        'vigente_desde' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('con dos conexiones reales se numera detrás del ganador', function () {
    $rival = DB::connection('rival');
    rivalPublica($rival, 'aviso_recoleccion', 1, 'La del rival');

    // Sin transacción envolvente: cada `publicar()` abre y cierra la suya, que
    // es el modo normal y el único donde el reintento resuelve la carrera.
    config(['database.default' => 'publicador']);

    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'El mío');

    expect($texto->version)->toBe(2)
        ->and(app(Textos::class)->vigente('aviso_recoleccion')?->contenido)->toBe('El mío')
        // Le cerró la vigencia a la fila del rival, escrita por otro PDO.
        ->and(TextoInformativo::query()->where('version', 1)->sole()->vigente_hasta)->not->toBeNull();
});

it('dentro de la transacción de quien llama no inventa un número: manda reintentarla', function () {
    $rival = DB::connection('rival');

    // El adoptante abre SU transacción y lee: ahí queda fijado el snapshot de
    // REPEATABLE READ, y en ese snapshot este texto no existe todavía.
    config(['database.default' => 'publicador']);
    $publicador = DB::connection('publicador');
    $publicador->beginTransaction();
    $publicador->table('privacidad_textos')->count();

    // Recién ahora el rival publica la v1 y commitea, en su propio PDO.
    //
    // Este orden es el que separa el reintento que sirve del que gira en falso.
    // Con lecturas comunes, el snapshot del publicador sigue diciendo que no hay
    // nada: los tres intentos calculan `max(version) + 1 = 1`, los tres chocan
    // contra la v1 del rival y el método termina culpando a la unicidad. Es el
    // único modo que ejercitaba la suite vieja, y no lo veía porque el rival
    // escribía en la misma conexión —una transacción siempre se ve a sí misma—.
    rivalPublica($rival, 'aviso_recoleccion', 1, 'La del rival');

    // Se afirma el desenlace de cada motor en vez de saltarse el que no toca:
    // un `skip` acá volvería a dejar verde una corrida que no probó nada, que es
    // el mismo defecto que este módulo ya arrastró en otra dimensión.
    if (aislamientoPorSnapshot()) {
        // MariaDB 11.6+: la lectura bloqueante no salta el snapshot, aborta.
        expect(fn () => app(Textos::class)->publicar('aviso_recoleccion', 'El mío'))
            ->toThrow(PublicacionEnConflicto::class, 'el motor abortó la operación por concurrencia');

        // Quien es dueño de la transacción es el único que puede reintentarla.
        $publicador->rollBack();

        expect(TextoInformativo::query()->count())->toBe(1)
            ->and(TextoInformativo::query()->vigentes()->sole()->contenido)->toBe('La del rival');

        return;
    }

    // MySQL, o MariaDB anterior a 11.6: la lectura bloqueante devuelve la última
    // versión comprometida, así que el publicador ve al ganador y se numera
    // detrás. Sin `lockForUpdate()` este caso terminaba en tres choques inútiles.
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'El mío');
    $publicador->commit();

    expect($texto->version)->toBe(2)
        ->and(TextoInformativo::query()->where('version', 1)->sole()->vigente_hasta)->not->toBeNull()
        ->and(TextoInformativo::query()->vigentes()->sole()->version)->toBe(2);
});

/**
 * ¿El motor trae `innodb_snapshot_isolation`?
 *
 * Prendido por defecto desde MariaDB 11.6.2, y cambia el desenlace de una
 * lectura bloqueante sobre una fila que cambió después del snapshot: en vez de
 * devolver la versión nueva, aborta con el 1020. La variable no existe en MySQL
 * ni en MariaDB anteriores, y ahí la consulta falla: eso es la respuesta «no».
 */
function aislamientoPorSnapshot(): bool
{
    return (bool) rescue(
        fn () => DB::connection('rival')->selectOne('select @@innodb_snapshot_isolation as v')->v,
        false,
        report: false,
    );
}

it('avisa en el lenguaje del dominio cuando el motor aborta por esperar el candado', function () {
    $rival = DB::connection('rival');
    rivalPublica($rival, 'aviso_recoleccion', 1, 'La del rival');

    // El rival deja la fila tomada y no la suelta.
    $rival->beginTransaction();
    $rival->table('privacidad_textos')->where('version', 1)->lockForUpdate()->get();

    config(['database.default' => 'publicador']);
    $publicador = DB::connection('publicador');
    // Un segundo de paciencia en vez de los 50 por defecto: lo que se prueba es
    // el desenlace, no la espera.
    $publicador->statement('set innodb_lock_wait_timeout = 1');

    expect(fn () => app(Textos::class)->publicar('aviso_recoleccion', 'El mío'))
        ->toThrow(PublicacionEnConflicto::class, 'el motor abortó la operación por concurrencia');

    $rival->rollBack();

    // El texto del rival quedó como estaba: vigente y con su contenido.
    $vigente = app(Textos::class)->vigente('aviso_recoleccion');

    expect($vigente?->version)->toBe(1)
        ->and($vigente?->contenido)->toBe('La del rival');
});
