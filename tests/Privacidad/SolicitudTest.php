<?php

use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\IdentidadNoVerificada;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->verificacion = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']);
});

it('calcula el vencimiento desde el plazo configurado', function () {
    config(['privacidad.plazo_respuesta_dias' => 30]);
    $this->travelTo('2026-09-01 10:00:00');

    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Rectificacion,
        'Mi apellido está mal escrito',
        $this->verificacion,
    );

    expect($solicitud->vence_en->toDateString())->toBe('2026-10-01')
        ->and($solicitud->estado)->toBe(EstadoDeSolicitud::Recibida);
});

it('guarda cómo se verificó la identidad del solicitante', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Quiero saber qué tienen de mí',
        $this->verificacion,
    );

    expect($solicitud->verificacion_identidad['metodo'])->toBe('cedula_presencial')
        ->and($solicitud->verificacion_identidad['evidencia'])->toBe(['run' => '11.111.111-1']);
});

it('rechaza registrar una solicitud si la identidad no se verificó', function () {
    $fallida = ResultadoVerificacion::fallida('cedula_presencial', 'la cédula no coincide');

    expect(fn () => app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Supresion,
        'Bórrenme',
        $fallida,
    ))->toThrow(IdentidadNoVerificada::class);
    expect(Solicitud::count())->toBe(0);
});

it('lista las solicitudes por vencer y las vencidas', function () {
    config(['privacidad.plazo_respuesta_dias' => 30]);
    $this->travelTo('2026-09-01 10:00:00');
    $servicio = app(Solicitudes::class);
    $servicio->registrar($this->titular, TipoDeSolicitud::Acceso, 'A', $this->verificacion);

    $this->travelTo('2026-09-28 10:00:00');
    expect(Solicitud::porVencer(5)->count())->toBe(1)
        ->and(Solicitud::vencidas()->count())->toBe(0);

    $this->travelTo('2026-10-05 10:00:00');
    expect(Solicitud::vencidas()->count())->toBe(1);
});
