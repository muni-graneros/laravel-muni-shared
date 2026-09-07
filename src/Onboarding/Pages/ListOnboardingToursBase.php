<?php

namespace Muni\Shared\Onboarding\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Página base del listado de Onboarding del panel.
 *
 * El `ListOnboardingTours` de cada sistema era, en 7 de los 8 (control-acceso,
 * seguridad, discapacidad, web, rrhh, feria, scaffold-laravel-filament-pwa),
 * byte a byte idéntico salvo por declarar su propio `$resource` — que no es
 * portable: cada sistema tiene SU `OnboardingTourResource`, con sus propias
 * columnas de tabla. `licencias-graneros` divergía solo en el estilo del
 * `use` (`Filament\Actions` + `Actions\CreateAction::make()` en vez de
 * `Filament\Actions\CreateAction` + `CreateAction::make()`), no en el
 * comportamiento.
 *
 * A diferencia de `Auditoria\ListActivitiesBase` -bare, porque el listado de
 * auditoría no traía ninguna acción de cabecera-, acá SÍ viaja
 * `getHeaderActions()`: es la misma funcionalidad duplicada en los 8
 * sistemas, no un detalle de estilo. Esta clase sigue siendo abstracta y NO
 * fija `$resource`.
 *
 * Adopción, por sistema:
 *
 * ```php
 * namespace App\Filament\Resources\OnboardingTourResource\Pages;
 *
 * use App\Filament\Resources\OnboardingTourResource;
 * use Muni\Shared\Onboarding\Pages\ListOnboardingToursBase;
 *
 * class ListOnboardingTours extends ListOnboardingToursBase
 * {
 *     protected static string $resource = OnboardingTourResource::class;
 * }
 * ```
 */
abstract class ListOnboardingToursBase extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
