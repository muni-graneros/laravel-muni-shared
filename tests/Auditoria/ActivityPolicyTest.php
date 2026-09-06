<?php

use Illuminate\Foundation\Auth\User as AuthUser;
use Muni\Shared\Auditoria\ActivityPolicy;
use Spatie\Activitylog\Models\Activity;

/**
 * Misma forma que `RolePolicyTest`: cada método delega en el permiso
 * homónimo (`view_any_activity`, …) que Filament Shield genera para el
 * Resource de auditoría (`spatie/laravel-activitylog`). Portada byte a byte
 * desde `App\Policies\ActivityPolicy`, idéntica en 6 de los 8 sistemas.
 *
 * `feria-graneros` diverge SOLO en estilo (tipa `App\Models\User` en vez de
 * `Illuminate\Foundation\Auth\User`, sin `declare(strict_types=1)`, con
 * docblocks en vez de nada): es una generación más vieja de Filament Shield,
 * no una regla de negocio distinta — mismo comportamiento, así que también
 * queda cubierta por esta versión.
 */
dataset('acciones_de_activity_policy', [
    'viewAny' => ['viewAny', 'view_any_activity', false],
    'view' => ['view', 'view_activity', true],
    'create' => ['create', 'create_activity', false],
    'update' => ['update', 'update_activity', true],
    'delete' => ['delete', 'delete_activity', true],
    'deleteAny' => ['deleteAny', 'delete_any_activity', false],
    'restore' => ['restore', 'restore_activity', true],
    'forceDelete' => ['forceDelete', 'force_delete_activity', true],
    'forceDeleteAny' => ['forceDeleteAny', 'force_delete_any_activity', false],
    'restoreAny' => ['restoreAny', 'restore_any_activity', false],
    'replicate' => ['replicate', 'replicate_activity', true],
    'reorder' => ['reorder', 'reorder_activity', false],
]);

it('autoriza cuando el usuario tiene el permiso, y niega cuando no', function (string $metodo, string $permiso, bool $recibeActivity) {
    $policy = new ActivityPolicy;
    $activity = new Activity;

    $autorizado = Mockery::mock(AuthUser::class);
    $autorizado->shouldReceive('can')->once()->with($permiso)->andReturnTrue();

    $noAutorizado = Mockery::mock(AuthUser::class);
    $noAutorizado->shouldReceive('can')->once()->with($permiso)->andReturnFalse();

    $argsOk = $recibeActivity ? [$autorizado, $activity] : [$autorizado];
    $argsNo = $recibeActivity ? [$noAutorizado, $activity] : [$noAutorizado];

    expect($policy->{$metodo}(...$argsOk))->toBeTrue()
        ->and($policy->{$metodo}(...$argsNo))->toBeFalse();
})->with('acciones_de_activity_policy');
