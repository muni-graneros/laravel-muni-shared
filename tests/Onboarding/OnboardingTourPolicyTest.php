<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as AuthUser;
use Muni\Shared\Onboarding\OnboardingTourPolicy;

/**
 * Misma forma que `RolePolicyTest` y `ActivityPolicyTest`: cada método
 * delega en el permiso homónimo (`view_any_onboarding_tour`, …) que
 * Filament Shield genera para el Resource de Onboarding. Portada byte a
 * byte desde `App\Policies\OnboardingTourPolicy`, idéntica en los 8
 * sistemas del ecosistema con panel Filament.
 *
 * A diferencia de `RolePolicy`/`ActivityPolicy` -que tipan el modelo del
 * VENDOR (`Spatie\...\Role`/`Activity`, disponible en este paquete como
 * `suggest`)-, `OnboardingTour` es un modelo LOCAL de cada sistema
 * (`App\Models\OnboardingTour`) que este paquete no posee y no puede
 * requerir. Por eso la política tipa `Illuminate\Database\Eloquent\Model`
 * en vez del modelo concreto: sigue siendo la clase que Laravel invoca vía
 * `Gate::getPolicyFor()` con la instancia real de `OnboardingTour`, que es
 * subclase de `Model` en los 8 sistemas.
 */
dataset('acciones_de_onboarding_tour_policy', [
    'viewAny' => ['viewAny', 'view_any_onboarding_tour', false],
    'view' => ['view', 'view_onboarding_tour', true],
    'create' => ['create', 'create_onboarding_tour', false],
    'update' => ['update', 'update_onboarding_tour', true],
    'delete' => ['delete', 'delete_onboarding_tour', true],
    'deleteAny' => ['deleteAny', 'delete_any_onboarding_tour', false],
    'restore' => ['restore', 'restore_onboarding_tour', true],
    'forceDelete' => ['forceDelete', 'force_delete_onboarding_tour', true],
    'forceDeleteAny' => ['forceDeleteAny', 'force_delete_any_onboarding_tour', false],
    'restoreAny' => ['restoreAny', 'restore_any_onboarding_tour', false],
    'replicate' => ['replicate', 'replicate_onboarding_tour', true],
    'reorder' => ['reorder', 'reorder_onboarding_tour', false],
]);

it('autoriza cuando el usuario tiene el permiso, y niega cuando no', function (string $metodo, string $permiso, bool $recibeModelo) {
    $policy = new OnboardingTourPolicy;
    $onboardingTour = new class extends Model {};

    $autorizado = Mockery::mock(AuthUser::class);
    $autorizado->shouldReceive('can')->once()->with($permiso)->andReturnTrue();

    $noAutorizado = Mockery::mock(AuthUser::class);
    $noAutorizado->shouldReceive('can')->once()->with($permiso)->andReturnFalse();

    $argsOk = $recibeModelo ? [$autorizado, $onboardingTour] : [$autorizado];
    $argsNo = $recibeModelo ? [$noAutorizado, $onboardingTour] : [$noAutorizado];

    expect($policy->{$metodo}(...$argsOk))->toBeTrue()
        ->and($policy->{$metodo}(...$argsNo))->toBeFalse();
})->with('acciones_de_onboarding_tour_policy');

it('no depende de Filament ni de spatie/permission ni de spatie/activitylog', function () {
    $reflexion = new ReflectionClass(OnboardingTourPolicy::class);
    $usos = array_map(
        fn (ReflectionParameter $parametro) => $parametro->getType()?->getName(),
        array_merge(...array_map(
            fn (ReflectionMethod $metodo) => $metodo->getParameters(),
            $reflexion->getMethods()
        ))
    );

    foreach ($usos as $tipo) {
        if ($tipo === null) {
            continue;
        }

        expect($tipo)->not->toContain('Filament');
        expect($tipo)->not->toContain('Spatie');
    }
});
