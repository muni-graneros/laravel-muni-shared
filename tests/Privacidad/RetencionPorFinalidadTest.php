<?php

use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\SupresionSoloLocal;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

/**
 * Una persona está terminada cuando TODAS las finalidades que la alcanzan
 * vencieron, no cuando venció la primera. El caso real que originó estas
 * pruebas: `agenda_citas` (24 meses) anonimizaba a 11.517 personas que
 * `registro_comunal` (120 meses) todavía tenía que conservar.
 */
beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    // Sin esto la retención se niega a ejecutar (ver SupresionEnElMaestroTest):
    // acá se declara explícitamente que el sistema de prueba no es modelo de
    // lectura del maestro, para poder aislar lo que estas pruebas miden.
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    $this->corta = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'agenda_citas',
        'nombre' => 'Agendamiento de citas',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'LOC de Municipalidades',
        'plazo_retencion_meses' => 24,
    ]);

    $this->larga = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 120,
    ]);

    // Vencida solo para la finalidad corta: 92 meses, a mitad de camino del
    // plazo del registro comunal. Es la persona id 34 de la corrida real.
    $this->aMedioCamino = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subMonths(92),
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    // Vencida para las dos.
    $this->terminada = PersonaDePrueba::create([
        'nombre' => 'Ema Ríos',
        'documento' => '33.333.333-3',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subMonths(130),
        'fecha_nacimiento' => now()->subYears(35)->toDateString(),
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

it('no anonimiza a quien todavía está dentro del plazo de otra finalidad', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $this->aMedioCamino->refresh();

    expect($this->aMedioCamino->nombre)->toBe('Rocío Paredes')
        ->and($this->aMedioCamino->documento)->toBe('11.111.111-1')
        ->and($this->aMedioCamino->diagnostico)->toBe('dato sensible de salud');
});

it('anonimiza a quien venció en todas las finalidades con plazo', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $this->terminada->refresh();

    expect($this->terminada->nombre)->toBe('ANONIMIZADO')
        ->and($this->terminada->documento)->toBeNull()
        ->and($this->terminada->diagnostico)->toBeNull();
});

it('el resumen distingue las personas distintas de la suma por finalidad', function () {
    // La suma por finalidad es 3 (2 en la corta + 1 en la larga) sobre 2
    // personas: leído como total, un funcionario cree que hay más gente por
    // suprimir de la que hay, y encima de la que se va a suprimir.
    $resumen = app(AplicarRetencion::class)->ejecutar(simulacion: true);

    expect($resumen->porFinalidad)->toBe([
        ['finalidad' => 'agenda_citas', 'titulares' => 2],
        ['finalidad' => 'registro_comunal', 'titulares' => 1],
    ])
        ->and($resumen->personas)->toBe(2)
        ->and($resumen->suprimibles)->toBe(1)
        ->and($resumen->suprimidos)->toBe(0);
});

it('la evidencia dice qué finalidades se consideraron, no solo la que venció primero', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $aplicada = EntradaBitacora::where('evento', 'retencion.aplicada')->sole();

    expect($aplicada->datos)->toBe([
        'finalidades' => ['agenda_citas' => 24, 'registro_comunal' => 120],
    ]);
});

it('el comando muestra cuántas personas distintas hay y cuántas se suprimirían', function () {
    $this->artisan('privacidad:aplicar-retencion')
        ->expectsOutputToContain('Personas distintas alcanzadas: 2')
        ->expectsOutputToContain('vencieron en TODAS')
        ->assertSuccessful();
});

it('una finalidad que no vence a nadie aparece en cero y explica por qué no se suprime a nadie', function () {
    // Basta que UNA finalidad todavía necesite a todos para que la intersección
    // quede vacía. Sin la fila en cero, eso se ve como «el comando no hizo
    // nada» y nadie sabe cuál de las finalidades lo está frenando.
    $this->larga->update(['plazo_retencion_meses' => 240]);

    $resumen = app(AplicarRetencion::class)->ejecutar(simulacion: true);

    expect($resumen->porFinalidad)->toBe([
        ['finalidad' => 'agenda_citas', 'titulares' => 2],
        ['finalidad' => 'registro_comunal', 'titulares' => 0],
    ])
        ->and($resumen->suprimibles)->toBe(0)
        ->and($resumen->sinNadaQueRevisar())->toBeFalse();
});

it('con una sola finalidad con plazo la intersección es esa finalidad', function () {
    // Regresión del caso degenerado: si la intersección se calculara contra el
    // total de finalidades del sistema (y no contra las que tienen plazo), un
    // sistema con una sola finalidad con plazo no anonimizaría a nadie nunca.
    $this->larga->update(['plazo_retencion_meses' => null]);

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    expect($this->aMedioCamino->refresh()->nombre)->toBe('ANONIMIZADO')
        ->and($this->terminada->refresh()->nombre)->toBe('ANONIMIZADO');
});
