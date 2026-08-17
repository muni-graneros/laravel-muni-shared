<?php

use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\SupresionNoPropagada;
use Muni\Shared\Privacidad\SupresionSoloLocal;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

/**
 * La constancia se escribía una sola vez, al cierre. En la corrida real el
 * proceso no llegó al cierre: 10.131 personas anonimizadas y cero constancias.
 * Un `finally` protege contra excepciones, no contra que maten el proceso.
 */
beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.retencion.lote' => 2]);

    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'atencion',
        'nombre' => 'Atención de casos',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 60,
    ]);

    foreach (['11.111.111-1', '22.222.222-2', '33.333.333-3'] as $i => $documento) {
        PersonaDePrueba::create([
            'nombre' => 'Titular '.$i,
            'documento' => $documento,
            'diagnostico' => 'dato sensible de salud',
            'tratamiento_iniciado_en' => now()->subYears(6),
            'fecha_nacimiento' => now()->subYears(40)->toDateString(),
        ]);
    }

    app()->bind(ResuelveTitularesVencidos::class, fn () => new class implements ResuelveTitularesVencidos
    {
        public function vencidos(Finalidad $finalidad): iterable
        {
            return PersonaDePrueba::query()
                ->whereNotNull('documento')
                ->where('tratamiento_iniciado_en', '<', now()->subMonths((int) $finalidad->plazo_retencion_meses))
                ->orderBy('id')
                ->get();
        }
    });
});

it('deja constancia por lote y no solo al final de la corrida', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $constancias = EntradaBitacora::where('evento', 'retencion.constancia')->orderBy('id')->get();

    // Acumuladas, no sumables: la última de una corrida es el total de esa
    // corrida. Sumarlas contaría dos veces a las mismas personas.
    expect($constancias)->toHaveCount(2)
        ->and($constancias[0]->datos['titulares'])->toBe(2)
        ->and($constancias[0]->datos['completa'])->toBeFalse()
        ->and($constancias[1]->datos['titulares'])->toBe(3)
        ->and($constancias[1]->datos['completa'])->toBeTrue()
        // Todas las constancias de una corrida comparten su token opaco: sin él
        // no hay forma de saber si tres constancias son una corrida o tres.
        ->and($constancias[0]->datos['corrida'])->toBe($constancias[1]->datos['corrida'])
        ->and($constancias[1]->datos['finalidades'])->toBe(['atencion' => 60]);
});

it('una corrida que revienta a mitad deja constancia de lo que alcanzó a hacer', function () {
    // El tercer titular no se puede propagar al maestro: la corrida aborta.
    app()->instance(PropagaSupresion::class, new class implements PropagaSupresion
    {
        private int $vistos = 0;

        public function propagar(TitularDeDatos $titular, string $documento): bool
        {
            return ++$this->vistos < 3;
        }
    });

    try {
        app(AplicarRetencion::class)->ejecutar(simulacion: false);
    } catch (SupresionNoPropagada) {
        // Lo que importa es lo que quedó escrito.
    }

    $constancias = EntradaBitacora::where('evento', 'retencion.constancia')->orderBy('id')->get();

    expect($constancias)->toHaveCount(1)
        ->and($constancias[0]->datos['titulares'])->toBe(2)
        // Ninguna constancia de la corrida dice `completa`: quien lea la
        // bitácora ve que la corrida no terminó, en vez de creer que 2 era todo.
        ->and($constancias[0]->datos['completa'])->toBeFalse();
});

it('la constancia sigue sin identificar a nadie', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $constancias = EntradaBitacora::where('evento', 'retencion.constancia')->get();

    expect($constancias->pluck('user_id')->unique()->all())->toBe([null])
        ->and($constancias->pluck('titular_id')->unique()->all())->toBe([null])
        ->and($constancias->pluck('titular_ref')->unique()->all())->toBe([null]);
});
