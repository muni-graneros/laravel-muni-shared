<?php

use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\NingunTitularVencido;
use Muni\Shared\Privacidad\SupresionSoloLocal;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;
use Muni\Shared\Tests\Privacidad\Fixtures\UsuarioDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    // Paso obligatorio desde que la supresión se propaga al maestro de personas:
    // sin declaración, `AplicarRetencion` se niega a ejecutar. Este archivo
    // declara «solo local», que es lo que corresponde a un sistema de prueba sin
    // maestro; la federación se ejercita en SupresionEnElMaestroTest.
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

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
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
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

    expect($resumen->porFinalidad)->toBe([['finalidad' => 'atencion', 'titulares' => 1]])
        ->and($resumen->suprimibles)->toBe(1)
        ->and($resumen->suprimidos)->toBe(0)
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

it('al ejecutar desvincula la bitácora previa del titular y la propia entrada de retención', function () {
    // Camino de producción end-to-end: si alguien borra la llamada a
    // desvincular() dentro de AplicarRetencion::aplicarA(), este test es el
    // que lo detecta — los unitarios de Bitacora nunca pasan por acá.
    $this->travelTo(now()->subMonths(2));
    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['x' => 1], $this->vencida);
    app(RegistroDeEvidencia::class)->registrar('solicitud.acogida', ['y' => 2], $this->vencida);
    $this->travelBack();

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    // Las dos previas se agrupan bajo la misma referencia aleatoria.
    $huerfanas = EntradaBitacora::whereNotNull('titular_ref')->get();

    // La propia 'retencion.aplicada' también queda desvinculada, pero FUERA del
    // grupo: se escribió dentro de la transacción de anonimización y lleva su
    // hora exacta, la misma que quedó congelada en el updated_at de la persona.
    // Con la referencia puesta, esa hora sería la llave para leer todo el caso.
    $deLaAnonimizacion = EntradaBitacora::where('evento', 'retencion.aplicada')->sole();

    expect($huerfanas)->toHaveCount(2)
        ->and($huerfanas->pluck('titular_ref')->unique())->toHaveCount(1)
        ->and($huerfanas->pluck('evento')->sort()->values()->all())->toBe([
            'solicitud.acogida',
            'solicitud.registrada',
        ])
        ->and($deLaAnonimizacion->titular_id)->toBeNull()
        ->and($deLaAnonimizacion->titular_ref)->toBeNull()
        ->and(EntradaBitacora::whereNotNull('titular_id')->count())->toBe(0);
});

it('las cantidades del barrido se publican una vez por corrida, no por titular', function () {
    // Las cantidades por persona —cuántas filas, cuántos documentos— estampadas
    // en el instante exacto de la anonimización acotan cada persona a un
    // conjunto de filas huérfanas de 2 a 6, y con el orden de las constancias se
    // resuelve el resto. Es el único de los tres canales de correlación
    // conocidos que el módulo controla, así que se agrega por corrida: la suma
    // sigue delatando el disco mal configurado sin decir de quién era cada fila.
    // Con sesión abierta: es el caso que hace peligroso al user_id de la
    // constancia. Sin esto, Auth::id() sería null y la aserción de más abajo no
    // probaría nada.
    $this->actingAs(new UsuarioDePrueba(['id' => 77]));

    $otra = PersonaDePrueba::create([
        'nombre' => 'Ema Ríos',
        'documento' => '33.333.333-3',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subYears(7),
        'fecha_nacimiento' => now()->subYears(35)->toDateString(),
    ]);

    $this->travelTo(now()->subMonths(2));
    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['x' => 1], $this->vencida);
    app(RegistroDeEvidencia::class)->registrar('solicitud.acogida', ['y' => 2], $this->vencida);
    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['z' => 3], $otra);
    $this->travelBack();

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $corrida = EntradaBitacora::where('evento', 'retencion.constancia')->sole();

    // Dos previas + su propia 'retencion.aplicada' para la primera; una previa +
    // la suya para la segunda.
    expect($corrida->datos['titulares'])->toBe(2)
        ->and($corrida->datos['filas'])->toBe(5)
        ->and($corrida->datos['archivos_suprimidos'])->toBe(0)
        ->and($corrida->datos['archivos_no_encontrados'])->toBe(0)
        ->and($corrida->datos['completa'])->toBeTrue()
        // Sin usuario y sin titular, igual que la constancia por titular: una
        // corrida disparada desde un panel con sesión abierta dejaría ahí el id
        // de quien la lanzó, en el instante de la anonimización.
        ->and($corrida->user_id)->toBeNull()
        ->and($corrida->titular_id)->toBeNull()
        ->and($corrida->titular_ref)->toBeNull();

    // Y por titular queda el hecho, idéntico para los dos: se cortó el vínculo.
    $porTitular = EntradaBitacora::where('evento', 'bitacora.desvinculada')->get();

    expect($porTitular)->toHaveCount(2)
        ->and($porTitular->pluck('datos')->all())->toBe([[], []]);
});

it('ignora finalidades sin plazo de retención', function () {
    $this->finalidad->update(['plazo_retencion_meses' => null]);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false)->sinNadaQueRevisar())->toBeTrue();
    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});

it('ignora finalidades inactivas', function () {
    $this->finalidad->update(['activa' => false]);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false)->sinNadaQueRevisar())->toBeTrue();
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

it('sin PRIVACIDAD_SISTEMA configurado el comando avisa en vez de reventar', function () {
    config(['privacidad.sistema' => null]);

    $this->artisan('privacidad:aplicar-retencion')
        ->expectsOutputToContain('no declaró ninguna finalidad vigente con plazo de retención')
        ->assertSuccessful();
});

it('un sistema que no implementó el resolvedor no purga nada en vez de reventar', function () {
    // Se deshace el enlace del beforeEach para simular un sistema recién instalado.
    app()->forgetInstance(ResuelveTitularesVencidos::class);
    app()->bind(ResuelveTitularesVencidos::class, NingunTitularVencido::class);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false)->sinNadaQueRevisar())->toBeTrue();
    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});
