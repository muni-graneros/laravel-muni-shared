<?php

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Rectificaciones;
use Muni\Shared\Privacidad\RectificacionNoPropagada;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocio Paredez', 'documento' => '11.111.111-1']);
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

        public function propagar(Model $titular, array $cambios): bool
        {
            $this->vistos[] = $cambios;

            return true;
        }
    });

    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    expect($this->titular->refresh()->nombre)->toBe('Rocío Paredes')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Acogida)
        ->and(EntradaBitacora::where('evento', 'rectificacion.aplicada')->count())->toBe(1)
        // Solo se guardan los NOMBRES de los campos corregidos, nunca los valores.
        ->and(EntradaBitacora::where('evento', 'rectificacion.aplicada')->sole()->datos)
        ->toBe(['solicitud_id' => $this->solicitud->id, 'campos' => ['nombre']]);
});

it('si el maestro rechaza el cambio, la solicitud NO queda resuelta', function () {
    app()->bind(PropagaRectificacion::class, fn () => new class implements PropagaRectificacion
    {
        public function propagar(Model $titular, array $cambios): bool
        {
            return false;
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
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::EnTramite)
        ->and(EntradaBitacora::where('evento', 'rectificacion.rechazada_por_maestro')->count())->toBe(1);
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
        public function propagar(Model $titular, array $cambios): bool
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
        ->and($solicitudConTitular->refresh()->estado)->toBe(EstadoDeSolicitud::EnTramite)
        ->and(EntradaBitacora::where('evento', 'rectificacion.rechazada_por_maestro')->count())->toBe(1);
});

it('sin propagador enlazado aplica solo local, para sistemas que no hablan con el maestro', function () {
    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    expect($this->titular->refresh()->nombre)->toBe('Rocío Paredes')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Acogida);
});

it('si el maestro rechaza, la instancia en memoria del titular también queda con el valor original', function () {
    app()->bind(PropagaRectificacion::class, fn () => new class implements PropagaRectificacion
    {
        public function propagar(Model $titular, array $cambios): bool
        {
            return false;
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
    ))->toThrow(RectificacionNoPropagada::class);

    // La solicitud de supresión no puede quedar acogida sin haberse suprimido nada.
    expect($supresion->refresh()->estado)->toBe(EstadoDeSolicitud::Recibida)
        ->and($this->titular->refresh()->nombre)->toBe('Rocio Paredez');
});

it('no acoge una rectificación sin cambios', function () {
    // Acogerla certificaría por escrito una corrección que no tocó ningún dato.
    expect(fn () => app(Rectificaciones::class)->aplicar($this->solicitud, [], 'Se verifica con cédula.'))
        ->toThrow(RectificacionNoPropagada::class);

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
