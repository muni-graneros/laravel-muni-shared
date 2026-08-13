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

it('avisa cuando el sistema no declaró ninguna finalidad con plazo de retención', function () {
    // Sin finalidades con plazo, el comando no recorre nada. Decir solo "no hay
    // vencidos" haría pasar por cumplimiento lo que en realidad es un sistema
    // sin política de retención sembrada.
    $this->finalidad->update(['plazo_retencion_meses' => null]);

    $this->artisan('privacidad:aplicar-retencion')
        ->expectsOutputToContain('no declaró ninguna finalidad vigente con plazo de retención')
        ->assertSuccessful();
});

it('avisa cuando el sistema no implementó el resolvedor de titulares vencidos', function () {
    app()->forgetInstance(ResuelveTitularesVencidos::class);
    app()->bind(ResuelveTitularesVencidos::class, NingunTitularVencido::class);

    $this->artisan('privacidad:aplicar-retencion')
        ->expectsOutputToContain('la retención NO está operativa')
        ->assertSuccessful();
});

it('no avisa de nada cuando la retención sí está operativa y simplemente no hay vencidos', function () {
    // El aviso tiene que ser señal, no ruido de fondo: un sistema bien
    // configurado y sin vencidos no debe imprimir advertencias, o el cron diario
    // enseña a ignorarlas.
    $this->vencida->update(['tratamiento_iniciado_en' => now()]);

    $this->artisan('privacidad:aplicar-retencion')
        ->doesntExpectOutputToContain('no declaró ninguna finalidad')
        ->doesntExpectOutputToContain('NO está operativa')
        ->assertSuccessful();
});

it('un sistema que no implementó el resolvedor no purga nada en vez de reventar', function () {
    // Se deshace el enlace del beforeEach para simular un sistema recién instalado.
    app()->forgetInstance(ResuelveTitularesVencidos::class);
    app()->bind(ResuelveTitularesVencidos::class, NingunTitularVencido::class);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false))->toBe([]);
    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});
