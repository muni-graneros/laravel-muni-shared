<?php

use Illuminate\Support\Facades\Http;
use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\ResultadoDePropagacion;
use Muni\Shared\Privacidad\SupresionEnCurso;
use Muni\Shared\Privacidad\SupresionNoPropagada;
use Muni\Shared\Privacidad\SupresionSoloLocal;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;
use Muni\Shared\Tests\Privacidad\Fixtures\SincronizarPersonaDePrueba;

/**
 * El defecto que originó estas pruebas no vivía en ninguna de las dos mitades:
 * anonimizar hace `save()`, el sistema adoptante tiene un observador `saved`
 * que empuja la persona al maestro federado, y el resultado medido en la base
 * del maestro fue la identidad real viva + una persona nueva `ANON-{id}`.
 * Cada mitad estaba probada y las dos pasaban.
 */
beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    PersonaDePrueba::$supresionActivaAlPurgar = null;
    PersonaDePrueba::$supresionActivaAlAnonimizar = null;

    Finalidad::create([
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
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    app()->bind(ResuelveTitularesVencidos::class, fn () => new class implements ResuelveTitularesVencidos
    {
        public function vencidos(Finalidad $finalidad): iterable
        {
            return PersonaDePrueba::query()
                ->whereNotNull('documento')
                ->where('tratamiento_iniciado_en', '<', now()->subMonths((int) $finalidad->plazo_retencion_meses))
                ->get();
        }
    });
});

it('anonimiza dentro del contexto de supresión, que es lo que el observador del adoptante consulta', function () {
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    expect(PersonaDePrueba::$supresionActivaAlPurgar)->toBeTrue()
        ->and(PersonaDePrueba::$supresionActivaAlAnonimizar)->toBeTrue()
        // Y el contexto se cierra al terminar: si quedara abierto, el sistema
        // dejaría de sincronizar al maestro para todo lo demás.
        ->and(SupresionEnCurso::activa())->toBeFalse();
});

it('el contexto se cierra aunque la supresión reviente', function () {
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    try {
        SupresionEnCurso::durante(function (): void {
            throw new RuntimeException('el disco falló');
        });
    } catch (RuntimeException) {
        // Lo que importa es el estado de después.
    }

    expect(SupresionEnCurso::activa())->toBeFalse();
});

it('el write-through no empuja al maestro lo que se escribió durante una supresión', function () {
    config(['services.personas_api' => ['driver' => 'http', 'url' => 'https://maestro.test', 'token' => 'x']]);
    Http::fake();

    // El job se CONSTRUYE dentro de la supresión (es lo que hace el observador
    // `saved` del adoptante) y se ejecuta después, ya fuera del contexto: por
    // eso la marca viaja con el job y no se consulta en handle().
    $job = SupresionEnCurso::durante(fn () => new SincronizarPersonaDePrueba($this->vencida->getKey()));

    $job->handle();

    Http::assertNothingSent();
});

it('el write-through no empuja un registro ya anonimizado, venga de donde venga', function () {
    // Esta es la segunda puerta al mismo defecto, y no la cierra el contexto:
    // el cron `personas:resincronizar` mira `updated_at > sincronizado_maestro_at`
    // y la anonimización mueve `updated_at`, así que quince minutos después
    // re-despacha la persona ya anonimizada y crea el `ANON-{id}` en el maestro.
    config(['services.personas_api' => ['driver' => 'http', 'url' => 'https://maestro.test', 'token' => 'x']]);
    Http::fake();

    $this->vencida->forceFill(['documento' => 'ANON-'.$this->vencida->getKey()])->save();

    (new SincronizarPersonaDePrueba($this->vencida->getKey()))->handle();

    Http::assertNothingSent();
});

it('sin supresión de por medio el write-through sí empuja: la guardia es lo que lo detiene', function () {
    config(['services.personas_api' => ['driver' => 'http', 'url' => 'https://maestro.test', 'token' => 'x']]);
    Http::fake();

    (new SincronizarPersonaDePrueba($this->vencida->getKey()))->handle();

    Http::assertSentCount(1);
});

it('se niega a ejecutar si el sistema no declaró qué pasa con el maestro', function () {
    // Destruir el dato local dejando la identidad viva en el registro federado
    // es peor que no suprimir: el municipio certificaría una supresión que no
    // ocurrió. Sin declaración explícita, no se toca nada.
    app(AplicarRetencion::class)->ejecutar(simulacion: false);
})->throws(SupresionNoPropagada::class);

it('la negativa se decide antes de tocar el primer titular', function () {
    try {
        app(AplicarRetencion::class)->ejecutar(simulacion: false);
    } catch (SupresionNoPropagada) {
        // Se comprueba abajo.
    }

    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});

it('la simulación sí corre sin la declaración, para poder ver el aviso antes', function () {
    $resumen = app(AplicarRetencion::class)->ejecutar(simulacion: true);

    expect($resumen->suprimibles)->toBe(1);
});

it('el comando avisa que la supresión no se propagará al maestro', function () {
    $this->artisan('privacidad:aplicar-retencion')
        ->expectsOutputToContain('no declaró qué debe pasar en el maestro')
        ->assertSuccessful();
});

it('propaga la supresión con el documento previo, antes de destruir nada local', function () {
    $espia = new class implements PropagaSupresion
    {
        public ?string $documento = null;

        public ?string $nombreAlPropagar = null;

        public ?bool $contextoActivo = null;

        public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
        {
            $this->documento = $documento;
            $this->nombreAlPropagar = $titular->titularNombre();
            // La propagación NO va dentro del contexto de supresión: el
            // adoptante la implementa sobre el mismo transporte al maestro, y
            // dentro del contexto su propio empuje quedaría descartado.
            $this->contextoActivo = SupresionEnCurso::activa();

            return ResultadoDePropagacion::aceptada();
        }
    };

    app()->instance(PropagaSupresion::class, $espia);

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    expect($espia->documento)->toBe('11.111.111-1')
        ->and($espia->nombreAlPropagar)->toBe('Rocío Paredes')
        ->and($espia->contextoActivo)->toBeFalse()
        ->and($this->vencida->refresh()->nombre)->toBe('ANONIMIZADO');
});

it('si el maestro rechaza la supresión no se destruye el dato local', function () {
    app()->instance(PropagaSupresion::class, new class implements PropagaSupresion
    {
        public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::rechazada();
        }
    });

    try {
        app(AplicarRetencion::class)->ejecutar(simulacion: false);
    } catch (SupresionNoPropagada) {
        // Se comprueba abajo.
    }

    $this->vencida->refresh();

    expect($this->vencida->nombre)->toBe('Rocío Paredes')
        ->and($this->vencida->diagnostico)->toBe('dato sensible de salud');
});

it('«no correspondía propagar» destruye lo local, pero NO queda escrito como una aceptación del maestro', function () {
    // El defecto que este estado vino a cerrar, entrando por donde entró en la
    // adopción real: `PropagaSupresionAlMaestro` devolvía `true` sin contactar
    // a nadie cuando el driver de la API no era `http`. La supresión local es
    // correcta —quien decide que no hay a quién hablarle es el sistema, no el
    // módulo— pero la evidencia no puede decir que el maestro aceptó.
    app()->instance(PropagaSupresion::class, new class implements PropagaSupresion
    {
        public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::noCorrespondia('El driver de la API de personas no es http en este ambiente.');
        }
    });

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $aplicada = EntradaBitacora::where('evento', 'retencion.aplicada')->sole();
    $constancia = EntradaBitacora::where('evento', 'retencion.constancia')->orderByDesc('id')->first();

    expect($this->vencida->refresh()->nombre)->toBe('ANONIMIZADO')
        ->and($aplicada->datos['propagacion'])->toBe([
            'estado' => 'no_correspondia',
            'motivo' => 'El driver de la API de personas no es http en este ambiente.',
        ])
        // Y la corrida entera lo dice en su agregado, que es lo que alguien lee
        // para responderle a la Agencia cuántas supresiones se propagaron.
        ->and($constancia->datos['titulares'])->toBe(1)
        ->and($constancia->datos['sin_propagar_al_maestro'])->toBe(1);
});

it('cuando el maestro sí acepta, la evidencia dice otra cosa', function () {
    // La otra mitad del test de arriba: si alguien colapsa los dos estados en
    // uno, estas dos evidencias se vuelven idénticas y el defecto revive.
    app()->instance(PropagaSupresion::class, new class implements PropagaSupresion
    {
        public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::aceptada();
        }
    });

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $constancia = EntradaBitacora::where('evento', 'retencion.constancia')->orderByDesc('id')->first();

    expect(EntradaBitacora::where('evento', 'retencion.aplicada')->sole()->datos['propagacion'])
        ->toBe(['estado' => 'aceptada', 'motivo' => null])
        ->and($constancia->datos['sin_propagar_al_maestro'])->toBe(0);
});

it('un sistema que declaró supresión solo local deja escrito que nadie habló con el maestro', function () {
    // `SupresionSoloLocal` es la declaración de entrada, y es legítima: este
    // sistema no es modelo de lectura del maestro. Lo que no puede es contarse
    // como aceptación —no hay maestro que haya aceptado nada— porque entonces
    // la evidencia de los ocho sistemas dice lo mismo hablen o no hablen.
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    expect(EntradaBitacora::where('evento', 'retencion.aplicada')->sole()->datos['propagacion']['estado'])
        ->toBe('no_correspondia')
        ->and(EntradaBitacora::where('evento', 'retencion.aplicada')->sole()->datos['propagacion']['motivo'])
        ->toContain('no es modelo de lectura del maestro');
});

it('el comando dice cuántas supresiones se hicieron sin hablar con el maestro', function () {
    // El otro sitio de consumo, y el que mira una persona: una corrida que
    // suprimió sin propagar no puede verse igual que una que propagó.
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    $this->artisan('privacidad:aplicar-retencion --ejecutar')
        ->expectsOutputToContain('Suprimidas sin hablar con el maestro de personas: 1')
        ->assertSuccessful();
});
