<?php

use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\NingunTitularVencido;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'atencion',
        'nombre' => 'Atención de casos',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 60,
    ]);

    $this->vencida = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subYears(6),
    ]);

    app()->bind(ResuelveTitularesVencidos::class, fn () => new class implements ResuelveTitularesVencidos
    {
        public function vencidos(Finalidad $finalidad): iterable
        {
            return PersonaDePrueba::query()
                ->whereNotNull('tratamiento_iniciado_en')
                ->where('tratamiento_iniciado_en', '<', now()->subMonths((int) $finalidad->plazo_retencion_meses))
                ->get();
        }
    });
});

it('en simulación informa a quién tocaría sin tocar nada', function () {
    $resumen = app(AplicarRetencion::class)->ejecutar(simulacion: true);

    expect($resumen)->toBe([['finalidad' => 'atencion', 'titulares' => 1]])
        ->and($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud')
        ->and($this->vencida->refresh()->nombre)->toBe('Rocío Paredes')
        ->and(EntradaBitacora::count())->toBe(0);
});

it('al ejecutar purga los sensibles y anonimiza, dejando evidencia', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $this->vencida->refresh();

    expect($this->vencida->diagnostico)->toBeNull()
        ->and($this->vencida->nombre)->toBe('ANONIMIZADO')
        ->and($this->vencida->documento)->toBeNull()
        ->and(EntradaBitacora::where('evento', 'retencion.aplicada')->count())->toBe(1);
});

it('ignora finalidades sin plazo de retención', function () {
    $this->finalidad->update(['plazo_retencion_meses' => null]);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false))->toBe([]);
    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});

it('ignora finalidades inactivas', function () {
    $this->finalidad->update(['activa' => false]);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false))->toBe([]);
    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});

it('el comando no toca nada sin --ejecutar', function () {
    $this->artisan('privacidad:aplicar-retencion')
        ->expectsOutputToContain('simulación')
        ->assertSuccessful();

    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});

it('el comando con --ejecutar aplica la retención', function () {
    $this->artisan('privacidad:aplicar-retencion --ejecutar')->assertSuccessful();

    expect($this->vencida->refresh()->nombre)->toBe('ANONIMIZADO');
});

it('un sistema que no implementó el resolvedor no purga nada en vez de reventar', function () {
    // Se deshace el enlace del beforeEach para simular un sistema recién instalado.
    app()->forgetInstance(ResuelveTitularesVencidos::class);
    app()->bind(ResuelveTitularesVencidos::class, NingunTitularVencido::class);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false))->toBe([]);
    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});
