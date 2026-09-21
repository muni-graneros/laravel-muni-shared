<?php

namespace Muni\Shared\Onboarding\Pages;

use Filament\Resources\Pages\CreateRecord;

/**
 * Página base de creación de Onboarding del panel.
 *
 * El `CreateOnboardingTour` de cada sistema era byte a byte idéntico en los
 * 8 (control-acceso, seguridad, discapacidad, web, rrhh, licencias, feria,
 * scaffold-laravel-filament-pwa): sin acciones de cabecera propias -Filament
 * ya pone "Crear" solo-, solo `$resource`. Por eso esta base queda tan
 * bare como `Auditoria\ListActivitiesBase`: no fija `$resource`, cada
 * sistema sigue apuntando a SU `OnboardingTourResource` local.
 *
 * Adopción, por sistema:
 *
 * ```php
 * namespace App\Filament\Resources\OnboardingTourResource\Pages;
 *
 * use App\Filament\Resources\OnboardingTourResource;
 * use Muni\Shared\Onboarding\Pages\CreateOnboardingTourBase;
 *
 * class CreateOnboardingTour extends CreateOnboardingTourBase
 * {
 *     protected static string $resource = OnboardingTourResource::class;
 * }
 * ```
 */
abstract class CreateOnboardingTourBase extends CreateRecord {}
