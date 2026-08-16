<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bloqueos;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.bloquear_durante_solicitud' => true]);
    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        // Adulta: el régimen de edad de Solicitudes exige la fecha acreditada.
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'atencion', 'nombre' => 'Atenciones',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);
    $this->verificacion = new ResultadoVerificacion(true, 'cedula_presencial');
});

it('un titular sin bloqueos no está bloqueado', function () {
    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});

it('bloquear una finalidad no bloquea las demás', function () {
    $otra = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);

    app(Bloqueos::class)->bloquear($this->titular, $this->finalidad, 'Rectificación en trámite');

    expect(app(Bloqueos::class)->vigente($this->titular, $this->finalidad))->toBeTrue()
        ->and(app(Bloqueos::class)->vigente($this->titular, $otra))->toBeFalse();
});

it('un bloqueo sin finalidad alcanza a todas', function () {
    app(Bloqueos::class)->bloquear($this->titular, null, 'Oposición general');

    expect(app(Bloqueos::class)->vigente($this->titular, $this->finalidad))->toBeTrue()
        ->and(app(Bloqueos::class)->vigente($this->titular))->toBeTrue();
});

it('registrar una rectificación bloquea automáticamente', function () {
    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal', $this->verificacion,
    );

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeTrue();
});

it('un acceso NO bloquea: no hay nada en disputa', function () {
    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Acceso, 'Quiero mis datos', $this->verificacion,
    );

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});

it('resolver la solicitud levanta su bloqueo', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal', $this->verificacion,
    );

    app(Solicitudes::class)->acoger($solicitud, 'Corregido con cédula a la vista.');

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse()
        // El bloqueo no se borra: queda con fecha de levantamiento.
        ->and(Bloqueo::count())->toBe(1)
        ->and(Bloqueo::sole()->levantado_en)->not->toBeNull();
});

it('rechazar la solicitud también levanta el bloqueo', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Oposicion, 'Me opongo', $this->verificacion,
    );

    app(Solicitudes::class)->rechazar($solicitud, 'No procede: el tratamiento se funda en la ley.');

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});

it('con la configuración apagada no bloquea nada', function () {
    config(['privacidad.bloquear_durante_solicitud' => false]);

    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal', $this->verificacion,
    );

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});
