<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `env:clean-data` vaciaba los datos operativos y estaba copiado byte a byte en
 * 5 de los 8 sistemas (más los dos scaffolds y web-graneros-centinela).
 *
 * Dos cosas cambian al portarlo, y las dos se prueban acá:
 *
 * 1. La lista de tablas era una constante. Era exactamente lo que iba a hacer
 *    que cada sistema volviera a bifurcar la copia en cuanto uno tuviera una
 *    tabla operativa propia. Ahora es configuración.
 * 2. El original desactivaba las claves foráneas con
 *    `SET FOREIGN_KEY_CHECKS=0`, que es SQL de MySQL/MariaDB y revienta en
 *    cualquier otro motor. Se reemplaza por `Schema::withoutForeignKeyConstraints()`,
 *    que hace lo mismo en el motor que toque. Esta suite corre en SQLite: con el
 *    SQL original, no habría forma de probar el comando.
 */
beforeEach(function () {
    foreach (['jobs', 'failed_jobs', 'activity_log', 'usuarios_de_prueba'] as $tabla) {
        Schema::create($tabla, function ($t) {
            $t->id();
        });
        DB::table($tabla)->insert([['id' => 1], ['id' => 2]]);
    }

    config()->set('datos-operativos.tablas', ['jobs', 'failed_jobs']);
    config()->set('datos-operativos.tablas_de_auditoria', ['activity_log']);
});

it('vacía las tablas operativas', function () {
    $this->artisan('env:clean-data --force')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('conserva lo que no está en la lista: las cuentas no se tocan', function () {
    $this->artisan('env:clean-data --force')->assertSuccessful();

    expect(DB::table('usuarios_de_prueba')->count())->toBe(2);
});

it('deja el registro de auditoría intacto salvo que se lo pidan', function () {
    $this->artisan('env:clean-data --force')->assertSuccessful();

    expect(DB::table('activity_log')->count())->toBe(2);
});

it('vacía también la auditoría con --auditoria', function () {
    $this->artisan('env:clean-data --force --auditoria')->assertSuccessful();

    expect(DB::table('activity_log')->count())->toBe(0);
});

it('ignora sin reventar una tabla configurada que no existe', function () {
    config()->set('datos-operativos.tablas', ['jobs', 'tabla_que_no_existe']);

    $this->artisan('env:clean-data --force')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0);
});

it('no hace nada y lo dice cuando ya está limpio', function () {
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();

    $this->artisan('env:clean-data --force')
        ->expectsOutputToContain('No hay datos operativos que limpiar.')
        ->assertSuccessful();
});

it('en producción no borra nada sin que alguien lo confirme', function () {
    // `Application::environment()` lee el binding «env» del contenedor, fijado
    // en el arranque: cambiar config('app.env') a esta altura no mueve nada, y
    // una prueba escrita así pasaría en verde sin ejercitar el freno.
    $this->app['env'] = 'production';

    $this->artisan('env:clean-data')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    expect(DB::table('jobs')->count())->toBe(2)
        ->and(DB::table('failed_jobs')->count())->toBe(2);
});

it('con --force sí borra en producción, que es como corre el despliegue', function () {
    $this->app['env'] = 'production';

    $this->artisan('env:clean-data --force')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0);
});
