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

it('una resolución sin fundamento no resuelve nada ni deja evidencia', function () {
    // Toda resolución debe ir fundada: el fundamento ES la respuesta que se le
    // entrega al titular. Un espacio en blanco no es un fundamento, y si el
    // guard fallara, la solicitud quedaría sellada como respondida sin decir
    // qué se le respondió.
    expect(fn () => $this->servicio->acoger($this->solicitud, '   '))
        ->toThrow(ResolucionInvalida::class);

    $this->solicitud->refresh();

    expect($this->solicitud->estado)->toBe(EstadoDeSolicitud::Recibida)
        ->and($this->solicitud->resuelta_en)->toBeNull()
        ->and(EntradaBitacora::where('evento', 'solicitud.acogida')->count())->toBe(0);
});

it('no permite resolver dos veces la misma solicitud', function () {
    $this->servicio->acoger($this->solicitud, 'Entregado.');

    expect(fn () => $this->servicio->rechazar($this->solicitud->refresh(), 'Otra cosa'))
        ->toThrow(ResolucionInvalida::class);
});

it('exportar desde la solicitud deja evidencia con los campos, nunca con los valores', function () {
    $datos = app(ExportacionDeDatos::class)->paraSolicitud($this->solicitud);

    $entrada = EntradaBitacora::where('evento', 'datos.exportados')->sole();

    expect($datos['datos'])->toBe(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1'])
        ->and($entrada->datos)->toBe([
            'solicitud_id' => $this->solicitud->id,
            'tipo' => 'acceso',
            'campos' => ['nombre', 'documento'],
        ])
        // La entrega del expediente completo queda ligada al titular concreto:
        // sin eso no hay forma de reconstruir a quién se le entregó qué.
        ->and($entrada->titular_id)->toBe($this->titular->id);
});

it('no exporta el expediente por una solicitud que no es de acceso ni de portabilidad', function () {
    // Una solicitud de supresión no habilita a llevarse la copia de los datos:
    // si el tipo no se verificara, cualquier solicitud registrada sería una
    // llave universal al expediente completo.
    $supresion = $this->servicio->registrar(
        $this->titular,
        TipoDeSolicitud::Supresion,
        'Bórrenme del registro',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );

    expect(fn () => app(ExportacionDeDatos::class)->paraSolicitud($supresion))
        ->toThrow(ResolucionInvalida::class);

    expect(EntradaBitacora::where('evento', 'datos.exportados')->count())->toBe(0);
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
