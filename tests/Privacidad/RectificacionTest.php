<?php

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\Rectificaciones;
use Muni\Shared\Privacidad\RectificacionNoPropagada;
use Muni\Shared\Privacidad\ResolucionInvalida;
use Muni\Shared\Privacidad\ResultadoDePropagacion;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocio Paredez',
        'documento' => '11.111.111-1',
        // Adulta: el régimen de edad de Solicitudes exige la fecha acreditada.
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
    $this->solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Rectificacion,
        'Mi apellido es Paredes, no Paredez',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );
});

it('aplica el cambio local y lo propaga al maestro', function () {
    $propagados = [];
    app()->bind(PropagaRectificacion::class, fn () => new class($propagados) implements PropagaRectificacion
    {
        public function __construct(public array &$vistos) {}

        public function propagar(Model $titular, array $cambios): ResultadoDePropagacion
        {
            $this->vistos[] = $cambios;

            return ResultadoDePropagacion::aceptada();
        }
    });

    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    expect($this->titular->refresh()->nombre)->toBe('Rocío Paredes')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Acogida)
        ->and(EntradaBitacora::where('evento', 'rectificacion.aplicada')->count())->toBe(1)
        // Solo se guardan los NOMBRES de los campos corregidos, nunca los valores.
        ->and(EntradaBitacora::where('evento', 'rectificacion.aplicada')->sole()->datos)
        ->toBe([
            'solicitud_id' => $this->solicitud->id,
            'campos' => ['nombre'],
            // Y que el maestro la aceptó de verdad, que es lo único que permite
            // certificarle al titular que el dato quedó corregido en todas las
            // ventanillas del ecosistema.
            'propagacion' => ['estado' => 'aceptada', 'motivo' => null],
        ]);
});

it('si el maestro rechaza el cambio, la solicitud NO queda resuelta', function () {
    app()->bind(PropagaRectificacion::class, fn () => new class implements PropagaRectificacion
    {
        public function propagar(Model $titular, array $cambios): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::rechazada();
        }
    });

    expect(fn () => app(Rectificaciones::class)->aplicar(
        $this->solicitud,
        ['nombre' => 'Rocío Paredes'],
        'Se verifica con cédula.',
    ))->toThrow(RectificacionNoPropagada::class);

    // El cambio local se revierte: quedarse con el dato corregido solo acá
    // garantiza que la próxima sincronización lo pise.
    expect($this->titular->refresh()->nombre)->toBe('Rocio Paredez')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::EnTramite);

    // La evidencia distingue el rechazo del maestro de cualquier otra falla.
    expect(EntradaBitacora::where('evento', 'rectificacion.fallida')->sole()->datos)->toBe([
        'solicitud_id' => $this->solicitud->id,
        'campos' => ['nombre'],
        'causa' => RectificacionNoPropagada::class,
        'rechazada_por_maestro' => true,
    ]);
});

it('si el propagador lanza, se trata igual que un rechazo y la excepción original sale intacta', function () {
    // El camino real del ecosistema: SincronizarAlMaestro llama a
    // $resp->throw() ante cualquier respuesta no exitosa, así que el rechazo
    // del maestro llega como excepción y NUNCA como false. Si el servicio solo
    // mirara RectificacionNoPropagada, este caso —el más probable en
    // producción— dejaría la solicitud sin evidencia y el titular en memoria
    // con el dato que el maestro no aceptó.
    app()->bind(PropagaRectificacion::class, fn () => new class implements PropagaRectificacion
    {
        public function propagar(Model $titular, array $cambios): ResultadoDePropagacion
        {
            throw new RuntimeException('El maestro respondió 422.');
        }
    });

    $solicitudConTitular = $this->solicitud->fresh(['titular']);
    $titularEnMemoria = $solicitudConTitular->titular;

    expect(fn () => app(Rectificaciones::class)->aplicar(
        $solicitudConTitular,
        ['nombre' => 'Rocío Paredes'],
        'Se verifica con cédula.',
    ))->toThrow(RuntimeException::class, 'El maestro respondió 422.');

    expect($this->titular->refresh()->nombre)->toBe('Rocio Paredez')
        ->and($titularEnMemoria->nombre)->toBe('Rocio Paredez')
        // La MISMA instancia de solicitud que acoger() mutó a "acogida", sin
        // volver a buscarla: el rollback la devolvió a en_tramite en la base, y
        // la pantalla que la tiene cargada no puede seguir mostrando "acogida".
        ->and($solicitudConTitular->estado)->toBe(EstadoDeSolicitud::EnTramite)
        ->and($solicitudConTitular->resuelta_en)->toBeNull();

    // Una falla que NO es rechazo del maestro no se archiva como si lo fuera.
    expect(EntradaBitacora::where('evento', 'rectificacion.fallida')->sole()->datos)->toBe([
        'solicitud_id' => $solicitudConTitular->id,
        'campos' => ['nombre'],
        'causa' => RuntimeException::class,
        'rechazada_por_maestro' => false,
    ]);
});

it('si la evidencia local falla después de acoger, la solicitud en memoria no queda diciendo «acogida»', function () {
    // Única ruta en la que acoger() alcanza a mutar la solicitud y la
    // transacción igual se revierte: el maestro ya aceptó y lo que falla es la
    // escritura local. La fila vuelve a en_tramite, y sin refresh() la pantalla
    // que tiene el objeto cargado seguiría mostrando "acogida" con su fecha de
    // resolución: una solicitud que el sistema cree resuelta y la base no.
    // Se enlaza la INSTANCIA (no una factory): Rectificaciones y Solicitudes
    // piden cada uno su RegistroDeEvidencia, y con bind() cada uno recibiría un
    // objeto distinto y el registro de lo visto quedaría repartido.
    $bitacora = new class implements RegistroDeEvidencia
    {
        /** @var list<string> */
        public array $vistos = [];

        public function registrar(string $evento, array $datos, ?Model $titular = null): void
        {
            if ($evento === 'rectificacion.aplicada') {
                throw new RuntimeException('La bitácora rechazó la entrada.');
            }

            $this->vistos[] = $evento;
        }
    };

    app()->instance(RegistroDeEvidencia::class, $bitacora);

    $solicitud = Solicitud::findOrFail($this->solicitud->getKey());

    expect(fn () => app(Rectificaciones::class)->aplicar(
        $solicitud,
        ['nombre' => 'Rocío Paredes'],
        'Se verifica con cédula.',
    ))->toThrow(RuntimeException::class, 'La bitácora rechazó la entrada.');

    // La MISMA instancia que acoger() mutó, sin volver a buscarla.
    expect($solicitud->estado)->toBe(EstadoDeSolicitud::EnTramite)
        ->and($solicitud->resuelta_en)->toBeNull()
        ->and(Solicitud::findOrFail($solicitud->getKey())->estado)->toBe(EstadoDeSolicitud::EnTramite)
        // Y el titular tampoco quedó con el valor que el rollback deshizo.
        ->and($this->titular->refresh()->nombre)->toBe('Rocio Paredez')
        ->and($bitacora->vistos)->toContain('rectificacion.fallida');
});

it('si el registro de la falla también falla, igual sale la excepción original', function () {
    // El caso para el que existe el guard: la conexión se cayó, así que ni el
    // refresh() ni la escritura de evidencia pueden funcionar. Lo que no puede
    // pasar es que el error del rescate REEMPLACE al que explica por qué no se
    // rectificó: quien atiende el mesón vería "la bitácora está caída" en vez
    // del rechazo del maestro, y el motivo real se perdería.
    app()->bind(PropagaRectificacion::class, fn () => new class implements PropagaRectificacion
    {
        public function propagar(Model $titular, array $cambios): ResultadoDePropagacion
        {
            throw new RuntimeException('El maestro respondió 422.');
        }
    });

    app()->instance(RegistroDeEvidencia::class, new class implements RegistroDeEvidencia
    {
        public function registrar(string $evento, array $datos, ?Model $titular = null): void
        {
            throw new LogicException('La bitácora está caída.');
        }
    });

    expect(fn () => app(Rectificaciones::class)->aplicar(
        $this->solicitud,
        ['nombre' => 'Rocío Paredes'],
        'Se verifica con cédula.',
    ))->toThrow(RuntimeException::class, 'El maestro respondió 422.');
});

it('sin propagador enlazado aplica solo local, para sistemas que no hablan con el maestro', function () {
    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    expect($this->titular->refresh()->nombre)->toBe('Rocío Paredes')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Acogida);
});

it('si el maestro rechaza, la instancia en memoria del titular también queda con el valor original', function () {
    app()->bind(PropagaRectificacion::class, fn () => new class implements PropagaRectificacion
    {
        public function propagar(Model $titular, array $cambios): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::rechazada();
        }
    });

    // Simula una pantalla que ya trae el titular cargado (p. ej. vía
    // Solicitud::with('titular')): la misma instancia se pasa al servicio.
    $solicitudConTitular = $this->solicitud->fresh(['titular']);
    $titularEnMemoria = $solicitudConTitular->titular;

    expect(fn () => app(Rectificaciones::class)->aplicar(
        $solicitudConTitular,
        ['nombre' => 'Rocío Paredes'],
        'Se verifica con cédula.',
    ))->toThrow(RectificacionNoPropagada::class);

    // No es un fetch nuevo: es la MISMA instancia que forceFill() mutó.
    expect($titularEnMemoria->nombre)->toBe('Rocio Paredez');
});

it('rechaza rectificar un campo que el titular no puede corregir, sin tocar el registro ni la solicitud', function () {
    expect(fn () => app(Rectificaciones::class)->aplicar(
        $this->solicitud,
        ['diagnostico' => 'dato clínico inventado por el solicitante'],
        'Se verifica con cédula.',
    ))->toThrow(RectificacionNoPropagada::class);

    expect($this->titular->refresh()->diagnostico)->toBeNull()
        // La validación es previa a tomar(): una solicitud malformada ni
        // siquiera mueve el estado.
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Recibida);
});

it('una rectificación sin fundamento no se registra como rechazo del maestro', function () {
    // Entrada realista de un operador. Si el fundamento vacío llegara hasta
    // acoger(), la excepción saldría desde dentro de la transacción y la
    // bitácora archivaría un rechazo del maestro que jamás ocurrió.
    $propagador = new class implements PropagaRectificacion
    {
        public bool $visto = false;

        public function propagar(Model $titular, array $cambios): ResultadoDePropagacion
        {
            $this->visto = true;

            return ResultadoDePropagacion::aceptada();
        }
    };

    app()->instance(PropagaRectificacion::class, $propagador);

    expect(fn () => app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], '   '))
        ->toThrow(ResolucionInvalida::class);

    // Ni siquiera se habló con el maestro: el guard corre antes de tomar().
    expect($propagador->visto)->toBeFalse()
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Recibida)
        ->and($this->titular->refresh()->nombre)->toBe('Rocio Paredez')
        ->and(EntradaBitacora::where('evento', 'rectificacion.fallida')->count())->toBe(0);
});

it('no rectifica una solicitud que pedía otra cosa', function () {
    $supresion = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Supresion,
        'Bórrenme del registro',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );

    expect(fn () => app(Rectificaciones::class)->aplicar(
        $supresion,
        ['nombre' => 'Rocío Paredes'],
        'Se verifica con cédula.',
    ))->toThrow(ResolucionInvalida::class);

    // La solicitud de supresión no puede quedar acogida sin haberse suprimido nada.
    expect($supresion->refresh()->estado)->toBe(EstadoDeSolicitud::Recibida)
        ->and($this->titular->refresh()->nombre)->toBe('Rocio Paredez');
});

it('no acoge una rectificación sin cambios', function () {
    // Acogerla certificaría por escrito una corrección que no tocó ningún dato.
    expect(fn () => app(Rectificaciones::class)->aplicar($this->solicitud, [], 'Se verifica con cédula.'))
        ->toThrow(ResolucionInvalida::class);

    expect($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Recibida);
});

it('no se puede rectificar una solicitud sin un titular vigente', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Rectificacion,
        'Corregir mi nombre',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );

    $this->titular->delete();

    expect(fn () => app(Rectificaciones::class)->aplicar(
        $solicitud->fresh(),
        ['nombre' => 'Rocío Paredes'],
        'Se verifica con cédula.',
    ))->toThrow(RectificacionNoPropagada::class);
});

it('sin propagador enlazado la evidencia dice que no correspondía propagar, no que el maestro aceptó', function () {
    // Un sistema que no es modelo de lectura del maestro rectifica solo local y
    // eso está bien. Lo que no está bien es que su evidencia se vea idéntica a
    // la de un sistema que sí propagó: son dos afirmaciones distintas sobre el
    // mismo derecho, y una fiscalización pregunta por la segunda.
    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    $datos = EntradaBitacora::where('evento', 'rectificacion.aplicada')->sole()->datos;

    expect($datos['propagacion']['estado'])->toBe('no_correspondia')
        ->and($datos['propagacion']['motivo'])->toContain('no enlazó');
});

it('un sistema con maestro que decide no hablarle deja escrito su motivo', function () {
    // El hueco que el tercer estado vino a cerrar, del lado de la
    // rectificación: el sistema SÍ es modelo de lectura del maestro, y en
    // tiempo de ejecución decide no contactarlo. Antes solo podía decirlo
    // devolviendo `true`, o sea mintiendo.
    app()->bind(PropagaRectificacion::class, fn () => new class implements PropagaRectificacion
    {
        public function propagar(Model $titular, array $cambios): ResultadoDePropagacion
        {
            return ResultadoDePropagacion::noCorrespondia('El titular no está dado de alta en el maestro de personas.');
        }
    });

    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    // La rectificación local se aplica —no hay a quién propagarle— pero el
    // registro no dice que el maestro la aceptó.
    expect($this->titular->refresh()->nombre)->toBe('Rocío Paredes')
        ->and(EntradaBitacora::where('evento', 'rectificacion.aplicada')->sole()->datos['propagacion'])
        ->toBe([
            'estado' => 'no_correspondia',
            'motivo' => 'El titular no está dado de alta en el maestro de personas.',
        ]);
});
