<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Muni\Shared\Persona\MaestroPersonaService;

beforeEach(function () {
    config([
        'services.personas_api.url' => 'http://personas-api:8000',
        'services.personas_api.token' => 'token-de-prueba',
    ]);
});

it('devuelve data y presencia (excluye el sistema propio) cuando la persona existe', function () {
    Http::fake([
        '*/api/servicios/v1/personas/*' => Http::response([
            'found' => true,
            'data' => ['nombres' => 'Óscar', 'apellidos' => 'Peña'],
            // presencia mixta: strings y objetos; incluye el propio (debe excluirse).
            'presencia' => ['feria', ['sistema' => 'discapacidad'], ['sistema' => 'licencias']],
        ], 200),
    ]);

    $r = (new MaestroPersonaService('discapacidad'))->buscar('21.444.666-6');

    expect($r)->not->toBeNull()
        ->and($r['data']['nombres'])->toBe('Óscar')
        ->and($r['presencia'])->toBe(['feria', 'licencias']); // 'discapacidad' (propio) fuera
});

it('un 404 significa persona nueva: data vacía, no null', function () {
    Http::fake(['*/api/servicios/v1/personas/*' => Http::response(['found' => false], 404)]);

    $r = (new MaestroPersonaService('feria'))->buscar('1-9');

    expect($r)->toBe(['data' => [], 'presencia' => []]);
});

it('degrada a null (no lanza) si el maestro responde con error', function () {
    Http::fake(['*/api/servicios/v1/personas/*' => Http::response('boom', 500)]);

    expect((new MaestroPersonaService('feria'))->buscar('1-9'))->toBeNull();
});

it('sin url/token configurados devuelve null sin llamar a la red', function () {
    config(['services.personas_api.url' => '', 'services.personas_api.token' => '']);
    Http::fake();

    expect((new MaestroPersonaService('feria'))->buscar('1-9'))->toBeNull();
    Http::assertNothingSent();
});

it('un RUT sin dígitos devuelve null', function () {
    expect((new MaestroPersonaService('feria'))->buscar('---'))->toBeNull();
});

// ── PII en el log: el RUT no puede terminar en laravel.log ni en GlitchTip ──

it('cuando el maestro no responde, lo que se loguea no lleva el RUT', function () {
    // Guzzle arma el mensaje con la URI completa —y el RUT va en el path—.
    // El `catch` de buscar() lo degradaba a null bien, pero escribía
    // `$e->getMessage()` tal cual en el contexto del log.
    Http::fake([
        '*/api/servicios/v1/personas/*' => fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received '
            .'for http://personas-api:8000/api/servicios/v1/personas/11111111-1',
        ),
    ]);

    $registros = [];
    Log::listen(function (MessageLogged $evento) use (&$registros): void {
        $registros[] = $evento->level.' '.$evento->message.' '.json_encode($evento->context);
    });

    expect((new MaestroPersonaService('feria'))->buscar('11111111-1'))->toBeNull();

    // Que sí se haya logueado algo: sin esto el test pasa en vacío.
    expect($registros)->not->toBe([])
        ->and(implode("\n", $registros))->not->toContain('11111111')
        // Y que lo que queda siga sirviendo para operar: la clase del fallo.
        ->and(implode("\n", $registros))->toContain('ConnectionException');
});
