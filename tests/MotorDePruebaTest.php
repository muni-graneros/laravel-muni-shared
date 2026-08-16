<?php

use Illuminate\Support\Facades\DB;
use Muni\Shared\Tests\TestCase;

/**
 * El guardia del guardia: que la suite no se salte el motor de producción sin
 * que se note.
 *
 * Sin `MUNI_MARIADB_HOST` la suite corre en SQLite, imprime «3 skipped» y sale
 * con 0 — exactamente el mismo verde que imprimiría un job llamado «Pest sobre
 * MariaDB» al que le renombraron o le borraron la variable. Un guardia que no
 * puede distinguir «corrí y pasó» de «no corrí» no acredita nada, que es el
 * mismo defecto que este módulo viene arrastrando en otras dimensiones.
 *
 * Este test cubre la mitad que se puede ver desde adentro: si alguien pide
 * MariaDB y la conexión resuelve a otra cosa, falla en vez de saltarse. La otra
 * mitad —que la variable siga existiendo en CI— no se puede comprobar desde la
 * suite y se comprueba contra el servidor: ver el paso «la corrida usó MariaDB
 * de verdad» en `.github/workflows/ci.yml` y la cola de `tools/pest-mariadb.sh`.
 */
it('no corre en SQLite cuando se le pidió MariaDB', function () {
    if (getenv('MUNI_MARIADB_HOST') === false) {
        // Nadie pidió MariaDB: SQLite es lo correcto y no hay nada que declarar.
        expect(DB::connection()->getDriverName())->toBe('sqlite');

        return;
    }

    expect(TestCase::hayMariaDb())->toBeTrue(
        'MUNI_MARIADB_HOST está puesta pero TestCase::hayMariaDb() no la ve: la suite corrió en SQLite creyendo que probaba producción.',
    )->and(DB::connection()->getDriverName())->toBe('mariadb');

    $version = (string) DB::selectOne('select version() as v')->v;

    expect($version)->toContain('MariaDB');
});
