<?php

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Muni\Shared\Onboarding\Pages\ListOnboardingToursBase;

/**
 * A diferencia de `Auditoria\ListActivitiesBase` -que queda vacía porque el
 * `ListActivities` de cada sistema no tenía ninguna acción de cabecera-, el
 * `ListOnboardingTours` de los 8 sistemas SÍ trae idéntico
 * `getHeaderActions(): [CreateAction::make()]`. Eso también es la
 * funcionalidad duplicada, no solo la herencia, así que viaja acá. Lo único
 * que cada sistema sigue declarando es su propio `$resource`: no es
 * portable, cada uno tiene SU `OnboardingTourResource`.
 */
it('es una página de listado de Filament, para que cada sistema declare su propio $resource', function () {
    expect(is_subclass_of(ListOnboardingToursBase::class, ListRecords::class))->toBeTrue();
});

it('no fija $resource: cada sistema apunta a SU OnboardingTourResource local', function () {
    $reflexion = new ReflectionClass(ListOnboardingToursBase::class);

    expect($reflexion->isAbstract())->toBeTrue();
});

it('un sistema que la extiende y declara $resource lo expone vía getResource()', function () {
    $paginaDelSistema = new class extends ListOnboardingToursBase
    {
        protected static string $resource = 'App\Filament\Resources\OnboardingTourResource';
    };

    expect($paginaDelSistema::getResource())->toBe('App\Filament\Resources\OnboardingTourResource');
});

it('trae el CreateAction de cabecera, idéntico en los 8 sistemas', function () {
    $paginaDelSistema = new class extends ListOnboardingToursBase
    {
        protected static string $resource = 'App\Filament\Resources\OnboardingTourResource';

        public function accionesDeCabeceraExpuestas(): array
        {
            return $this->getHeaderActions();
        }
    };

    $acciones = $paginaDelSistema->accionesDeCabeceraExpuestas();

    expect($acciones)->toHaveCount(1)
        ->and($acciones[0])->toBeInstanceOf(CreateAction::class);
});
