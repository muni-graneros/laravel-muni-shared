<?php

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Muni\Shared\Onboarding\Pages\EditOnboardingTourBase;

/**
 * Misma forma que `ListOnboardingToursBaseTest`: el `EditOnboardingTour` de
 * 6 de los 8 sistemas era byte a byte idéntico, y `licencias-graneros` /
 * `feria-graneros` divergían solo en el estilo del `use` (`Actions\DeleteAction`
 * vs `DeleteAction`), no en el comportamiento -misma generación más vieja de
 * Filament Shield que ya se documentó en `ActivityPolicy`-. `$resource`
 * sigue sin fijarse: no es portable.
 */
it('es una página de edición de Filament, para que cada sistema declare su propio $resource', function () {
    expect(is_subclass_of(EditOnboardingTourBase::class, EditRecord::class))->toBeTrue();
});

it('no fija $resource: cada sistema apunta a SU OnboardingTourResource local', function () {
    $reflexion = new ReflectionClass(EditOnboardingTourBase::class);

    expect($reflexion->isAbstract())->toBeTrue();
});

it('un sistema que la extiende y declara $resource lo expone vía getResource()', function () {
    $paginaDelSistema = new class extends EditOnboardingTourBase
    {
        protected static string $resource = 'App\Filament\Resources\OnboardingTourResource';
    };

    expect($paginaDelSistema::getResource())->toBe('App\Filament\Resources\OnboardingTourResource');
});

it('trae el DeleteAction de cabecera, idéntico en los 8 sistemas', function () {
    $paginaDelSistema = new class extends EditOnboardingTourBase
    {
        protected static string $resource = 'App\Filament\Resources\OnboardingTourResource';

        public function accionesDeCabeceraExpuestas(): array
        {
            return $this->getHeaderActions();
        }
    };

    $acciones = $paginaDelSistema->accionesDeCabeceraExpuestas();

    expect($acciones)->toHaveCount(1)
        ->and($acciones[0])->toBeInstanceOf(DeleteAction::class);
});
