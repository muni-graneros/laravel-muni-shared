<?php

namespace Muni\Shared\Onboarding;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Política del Resource de Onboarding del panel (Filament + Filament Shield).
 *
 * Byte a byte idéntica en los 8 sistemas del ecosistema con panel Filament
 * (licencias, discapacidad, seguridad, control-acceso, web, rrhh, feria,
 * scaffold-laravel-filament-pwa; `atencionvecino` no tiene panel Filament).
 * No decide nada por dominio: cada método delega en el permiso homónimo que
 * Filament Shield genera y muestra como checkbox en la pantalla de Roles
 * (`view_any_onboarding_tour`, `create_onboarding_tour`, …), así que no hay
 * ningún nombre de rol que hardcodear acá — eso lo resuelve `Gate::before`
 * en cada sistema, fuera de esta política.
 *
 * A diferencia de `Auditoria\RolePolicy` / `ActivityPolicy` -que tipan el
 * modelo del VENDOR (`Spatie\...\Role` / `Activity`, disponible acá como
 * `suggest`)-, el modelo de Onboarding (`App\Models\OnboardingTour`) es
 * LOCAL de cada sistema: este paquete no lo posee y no puede requerirlo (ni
 * siquiera como `suggest`, porque no es una clase publicada por nadie). Por
 * eso los métodos tipan `Illuminate\Database\Eloquent\Model` en vez del
 * modelo concreto: Laravel resuelve la política por la clase del modelo
 * (`Gate::getPolicyFor()`) y la invoca con la instancia real, que en los 8
 * sistemas es una subclase de `Model`.
 */
class OnboardingTourPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_onboarding_tour');
    }

    public function view(AuthUser $authUser, Model $onboardingTour): bool
    {
        return $authUser->can('view_onboarding_tour');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_onboarding_tour');
    }

    public function update(AuthUser $authUser, Model $onboardingTour): bool
    {
        return $authUser->can('update_onboarding_tour');
    }

    public function delete(AuthUser $authUser, Model $onboardingTour): bool
    {
        return $authUser->can('delete_onboarding_tour');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_onboarding_tour');
    }

    public function restore(AuthUser $authUser, Model $onboardingTour): bool
    {
        return $authUser->can('restore_onboarding_tour');
    }

    public function forceDelete(AuthUser $authUser, Model $onboardingTour): bool
    {
        return $authUser->can('force_delete_onboarding_tour');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_onboarding_tour');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_onboarding_tour');
    }

    public function replicate(AuthUser $authUser, Model $onboardingTour): bool
    {
        return $authUser->can('replicate_onboarding_tour');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_onboarding_tour');
    }
}
