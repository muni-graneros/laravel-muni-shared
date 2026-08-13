<?php

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
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
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Acogida);
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
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::EnTramite);
});

it('sin propagador enlazado aplica solo local, para sistemas que no hablan con el maestro', function () {
    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    expect($this->titular->refresh()->nombre)->toBe('Rocío Paredes')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Acogida);
});
