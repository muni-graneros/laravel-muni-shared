<?php

use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\ExportacionDeDatos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\ResolucionInvalida;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->servicio = app(Solicitudes::class);
    $this->solicitud = $this->servicio->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Quiero saber qué tienen de mí',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );
});

it('acoger una solicitud la sella con fecha, fundamento y evidencia', function () {
    $this->servicio->acoger($this->solicitud, 'Se entregó informe impreso al titular.');

    $this->solicitud->refresh();

    expect($this->solicitud->estado)->toBe(EstadoDeSolicitud::Acogida)
        ->and($this->solicitud->resuelta_en)->not->toBeNull()
        ->and($this->solicitud->fundamento_resolucion)->toBe('Se entregó informe impreso al titular.')
        ->and(EntradaBitacora::where('evento', 'solicitud.acogida')->count())->toBe(1);
});

it('rechazar exige un fundamento y deja el estado rechazado', function () {
    $this->servicio->rechazar($this->solicitud, 'El solicitante no acredita representación del titular.');

    $this->solicitud->refresh();

    expect($this->solicitud->estado)->toBe(EstadoDeSolicitud::Rechazada)
        ->and($this->solicitud->fundamento_resolucion)->not->toBeNull();
});

it('no permite resolver dos veces la misma solicitud', function () {
    $this->servicio->acoger($this->solicitud, 'Entregado.');

    expect(fn () => $this->servicio->rechazar($this->solicitud->refresh(), 'Otra cosa'))
        ->toThrow(ResolucionInvalida::class);
});

it('exporta los datos personales del titular para acceso y portabilidad', function () {
    $datos = app(ExportacionDeDatos::class)->paraTitular($this->titular);

    expect($datos['titular']['nombre'])->toBe('Rocío Paredes')
        ->and($datos['datos'])->toBe(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1'])
        ->and($datos['responsable'])->toHaveKey('nombre');
});

it('la exportación en json es válida y legible', function () {
    $json = app(ExportacionDeDatos::class)->comoJson($this->titular);

    expect(json_decode($json, true))->toBeArray()
        ->and($json)->toContain('Rocío Paredes'); // sin escapar unicode
});
