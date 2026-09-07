<?php

use Filament\Resources\Pages\CreateRecord;
use Muni\Shared\Onboarding\Pages\CreateOnboardingTourBase;

/**
 * El `CreateOnboardingTour` era byte a byte idéntico en los 8 sistemas: sin
 * acciones de cabecera propias (Filament ya pone "Crear" solo), solo
 * `$resource`. Por eso esta base queda tan vacía como
 * `Auditoria\ListActivitiesBase`.
 */
it('es una página de creación de Filament, para que cada sistema declare su propio $resource', function () {
    expect(is_subclass_of(CreateOnboardingTourBase::class, CreateRecord::class))->toBeTrue();
});

it('no fija $resource: cada sistema apunta a SU OnboardingTourResource local', function () {
    $reflexion = new ReflectionClass(CreateOnboardingTourBase::class);

    expect($reflexion->isAbstract())->toBeTrue();
});

it('un sistema que la extiende y declara $resource lo expone vía getResource()', function () {
    $paginaDelSistema = new class extends CreateOnboardingTourBase
    {
        protected static string $resource = 'App\Filament\Resources\OnboardingTourResource';
    };

    expect($paginaDelSistema::getResource())->toBe('App\Filament\Resources\OnboardingTourResource');
});
