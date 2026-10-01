<?php

use Muni\Shared\Testing\AssertNingunPermisoHuerfano;
use PHPUnit\Framework\ExpectationFailedException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Caso real: el permiso del widget ARCOP que Shield generó y ningún rol del
 * seeder recibía. Aquí el «seeder» es la propia prueba.
 */
uses(AssertNingunPermisoHuerfano::class)->in(__DIR__.'/AssertNingunPermisoHuerfanoTest.php');

beforeEach(function () {
    // El paquete no trae las tablas de spatie (las pone cada sistema): se crean con su stub.
    $stub = __DIR__.'/../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
    (require $stub)->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->rol = Role::create(['name' => 'supervisor']);
    $this->cubierto = Permission::create(['name' => 'ver_solicitudes']);
    $this->rol->givePermissionTo($this->cubierto);
});

it('pasa cuando todo permiso lo recibe algún rol', function () {
    $this->assertNingunPermisoHuerfano();
});

it('falla nombrando el permiso que ningún rol recibe', function () {
    Permission::create(['name' => 'widget_solicitudes_arcop_por_vencer_widget']);

    expect(fn () => $this->assertNingunPermisoHuerfano())
        ->toThrow(ExpectationFailedException::class, 'widget_solicitudes_arcop_por_vencer_widget');
});

it('una excepción explícita deja pasar al permiso que es a propósito de super_admin', function () {
    Permission::create(['name' => 'ver_diagnostico_interno']);

    $this->assertNingunPermisoHuerfano(excepciones: ['ver_diagnostico_interno']);
});

it('una excepción que ya recibe un rol, o que ya no existe, también falla', function () {
    expect(fn () => $this->assertNingunPermisoHuerfano(excepciones: ['ver_solicitudes']))
        ->toThrow(ExpectationFailedException::class, 'ya la recibe algún rol');

    expect(fn () => $this->assertNingunPermisoHuerfano(excepciones: ['borrado_hace_tiempo']))
        ->toThrow(ExpectationFailedException::class, 'ya no es un permiso existente');
});

it('sin permisos en la base falla en vez de pasar en vacío (seeder sin correr)', function () {
    Permission::query()->delete();

    expect(fn () => $this->assertNingunPermisoHuerfano())
        ->toThrow(ExpectationFailedException::class, 'seeder');
});

it('se puede acotar a un guard', function () {
    Permission::create(['name' => 'solo_api', 'guard_name' => 'api']);

    $this->assertNingunPermisoHuerfano(guard: 'web');

    expect(fn () => $this->assertNingunPermisoHuerfano(guard: 'api'))
        ->toThrow(ExpectationFailedException::class, 'solo_api');
});

it('un guard sin permisos (p. ej. mal escrito) falla en vez de pasar en vacío', function () {
    expect(fn () => $this->assertNingunPermisoHuerfano(guard: 'wbe'))
        ->toThrow(ExpectationFailedException::class, 'seeder');
});
