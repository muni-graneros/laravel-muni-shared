<?php

namespace Muni\Shared\Onboarding\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Página base de edición de Onboarding del panel.
 *
 * El `EditOnboardingTour` de cada sistema era, en 6 de los 8 (control-acceso,
 * seguridad, discapacidad, web, rrhh, scaffold-laravel-filament-pwa), byte a
 * byte idéntico salvo por `$resource`. `licencias-graneros` y
 * `feria-graneros` divergían solo en el estilo del `use`
 * (`Actions\DeleteAction::make()` en vez de `DeleteAction::make()`), la
 * misma generación más vieja de Filament Shield que ya se documentó en
 * `Auditoria\ActivityPolicy` — deuda de estilo, no una regla de negocio
 * distinta, así que también queda cubierta acá.
 *
 * `$resource` sigue sin fijarse: cada sistema apunta a SU
 * `OnboardingTourResource` local.
 *
 * Adopción, por sistema:
 *
 * ```php
 * namespace App\Filament\Resources\OnboardingTourResource\Pages;
 *
 * use App\Filament\Resources\OnboardingTourResource;
 * use Muni\Shared\Onboarding\Pages\EditOnboardingTourBase;
 *
 * class EditOnboardingTour extends EditOnboardingTourBase
 * {
 *     protected static string $resource = OnboardingTourResource::class;
 * }
 * ```
 */
abstract class EditOnboardingTourBase extends EditRecord
{
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
