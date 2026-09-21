<?php

use Illuminate\Foundation\Auth\User as AuthUser;
use Muni\Shared\Auditoria\RolePolicy;
use Spatie\Permission\Models\Role;

/**
 * `RolePolicy` no decide nada por sí sola: delega TODO en el permiso homónimo
 * de spatie/permission (`$authUser->can('view_any_role')`, etc.), que es lo
 * que Filament Shield muestra como checkbox en la pantalla de Roles. Por eso
 * el candado no es "¿qué rol puede?" sino "¿el método llama al permiso que
 * dice llamar, en los dos sentidos?": con permiso autoriza, sin permiso niega.
 *
 * Portada byte a byte desde `App\Policies\RolePolicy`, idéntica en 7 de los 8
 * sistemas del ecosistema (atencionvecino no tiene panel Filament).
 */
dataset('acciones_de_role_policy', [
    'viewAny' => ['viewAny', 'view_any_role', false],
    'view' => ['view', 'view_role', true],
    'create' => ['create', 'create_role', false],
    'update' => ['update', 'update_role', true],
    'delete' => ['delete', 'delete_role', true],
    'deleteAny' => ['deleteAny', 'delete_any_role', false],
    'restore' => ['restore', 'restore_role', true],
    'forceDelete' => ['forceDelete', 'force_delete_role', true],
    'forceDeleteAny' => ['forceDeleteAny', 'force_delete_any_role', false],
    'restoreAny' => ['restoreAny', 'restore_any_role', false],
    'replicate' => ['replicate', 'replicate_role', true],
    'reorder' => ['reorder', 'reorder_role', false],
]);

it('autoriza cuando el usuario tiene el permiso, y niega cuando no', function (string $metodo, string $permiso, bool $recibeRole) {
    $policy = new RolePolicy;
    $role = new Role(['name' => 'demo']);

    $autorizado = Mockery::mock(AuthUser::class);
    $autorizado->shouldReceive('can')->once()->with($permiso)->andReturnTrue();

    $noAutorizado = Mockery::mock(AuthUser::class);
    $noAutorizado->shouldReceive('can')->once()->with($permiso)->andReturnFalse();

    $argsOk = $recibeRole ? [$autorizado, $role] : [$autorizado];
    $argsNo = $recibeRole ? [$noAutorizado, $role] : [$noAutorizado];

    expect($policy->{$metodo}(...$argsOk))->toBeTrue()
        ->and($policy->{$metodo}(...$argsNo))->toBeFalse();
})->with('acciones_de_role_policy');
