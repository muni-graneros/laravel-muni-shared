<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Muni\Shared\Geocoder;
use Muni\Shared\GeocoderNoDisponible;

/**
 * Respuesta de Photon con una sola sugerencia chilena, tal como la devuelve el
 * servicio (GeoJSON: coordenadas en orden [lon, lat]).
 *
 * @return array<string, mixed>
 */
function respuestaPhoton(): array
{
    return [
        'features' => [[
            'properties' => ['countrycode' => 'CL', 'street' => 'Avenida Central', 'housenumber' => '123', 'city' => 'Graneros'],
            'geometry' => ['coordinates' => [-70.7297, -34.0664]],
        ]],
    ];
}

it('devuelve coordenadas cuando Nominatim responde', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '-34.0664', 'lon' => '-70.7297', 'display_name' => 'Los Quintos 034, Graneros'],
        ]),
    ]);

    $r = Geocoder::buscar('Los Quintos 034', 'Centro');

    expect($r)->not->toBeNull()
        ->and($r['lat'])->toBe(-34.0664)
        ->and($r['lng'])->toBe(-70.7297)
        ->and($r['aproximado'])->toBeFalse();
});

it('retorna null con dirección vacía', function () {
    expect(Geocoder::buscar('', ''))->toBeNull();
});

// ── buscarEstricto: «no pude preguntar» ya no se parece a «no existe» ──

it('buscarEstricto lanza GeocoderNoDisponible cuando la red falla', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => fn () => throw new ConnectionException('timeout')]);

    Geocoder::buscarEstricto('Los Quintos 034');
})->throws(GeocoderNoDisponible::class);

it('buscarEstricto lanza GeocoderNoDisponible cuando Nominatim responde con error', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response(null, 503)]);

    Geocoder::buscarEstricto('Los Quintos 034');
})->throws(GeocoderNoDisponible::class);

it('buscarEstricto devuelve null, sin lanzar, cuando Nominatim no conoce la dirección', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

    expect(Geocoder::buscarEstricto('Calle Que No Existe 9999'))->toBeNull();
});

it('buscar conserva su contrato: null ante un fallo de red', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => fn () => throw new ConnectionException('timeout')]);

    expect(Geocoder::buscar('Los Quintos 034'))->toBeNull();
});

it('un fallo del proveedor no queda en caché: la siguiente consulta vuelve a preguntar', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::sequence()
            ->pushStatus(503)
            ->push([['lat' => '-34.0664', 'lon' => '-70.7297', 'display_name' => 'Los Quintos 034']]),
    ]);

    expect(Geocoder::buscar('Los Quintos 034'))->toBeNull();

    $r = Geocoder::buscarEstricto('Los Quintos 034');

    expect($r)->not->toBeNull()
        ->and($r['lat'])->toBe(-34.0664);
    Http::assertSentCount(2);
});

it('un acierto sí queda en caché: la segunda consulta no sale a la red', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '-34.0664', 'lon' => '-70.7297', 'display_name' => 'Los Quintos 034'],
        ]),
    ]);

    Geocoder::buscarEstricto('Los Quintos 034');
    Geocoder::buscarEstricto('los quintos 034');

    Http::assertSentCount(1);
});

// ── sugerencias: la lista vacía de un fallo no se guarda 12 horas ──

it('sugerencias no cachea la lista vacía que deja un fallo de red', function () {
    Http::fake([
        'photon.komoot.io/*' => Http::sequence()
            ->pushStatus(502)
            ->push(respuestaPhoton()),
    ]);

    expect(Geocoder::sugerencias('avenida central'))->toBe([]);

    $sugerencias = Geocoder::sugerencias('avenida central');

    expect($sugerencias)->toHaveCount(1)
        ->and($sugerencias[0]['label'])->toBe('Avenida Central 123, Graneros');
    Http::assertSentCount(2);
});

it('sugerencias sí cachea una lista vacía que Photon devolvió de verdad', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(['features' => []])]);

    expect(Geocoder::sugerencias('nada por aquí'))->toBe([])
        ->and(Geocoder::sugerencias('nada por aquí'))->toBe([]);

    Http::assertSentCount(1);
});

it('sugerencias sigue devolviendo lista vacía ante una excepción de red', function () {
    Http::fake(['photon.komoot.io/*' => fn () => throw new ConnectionException('timeout')]);

    expect(Geocoder::sugerencias('avenida central'))->toBe([]);
});

// ── PII en el log: la dirección del vecino no puede terminar en laravel.log ──

/**
 * Mensaje tal como lo arma Guzzle ante un timeout: con la URI completa, y en la
 * query va la dirección que se está geocodificando.
 */
function timeoutConLaDireccionEnLaUri(string $host, string $direccion): ConnectionException
{
    return new ConnectionException(
        'cURL error 28: Operation timed out after 8000 milliseconds with 0 bytes received '
        ."for https://{$host}/search?q=".rawurlencode($direccion).'&format=json&limit=1',
    );
}

it('un fallo de red con Nominatim se loguea sin la dirección consultada', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => fn () => throw timeoutConLaDireccionEnLaUri(
            'nominatim.openstreetmap.org', 'Los Quintos 034, Graneros, Región de O\'Higgins, Chile',
        ),
    ]);

    $registros = [];
    Log::listen(function (MessageLogged $evento) use (&$registros): void {
        $registros[] = $evento->level.' '.$evento->message.' '.json_encode($evento->context);
    });

    expect(Geocoder::buscar('Los Quintos 034'))->toBeNull();

    $log = implode("\n", $registros);

    expect($registros)->not->toBe([])
        // Ni como la escribió el vecino ni como viaja en la URI («Los%20Quintos»).
        ->and($log)->not->toContain('Quintos')
        ->and($log)->toContain('ConnectionException');
});

it('un fallo de red con Photon se loguea sin el texto que escribió el vecino', function () {
    Http::fake([
        'photon.komoot.io/*' => fn () => throw timeoutConLaDireccionEnLaUri('photon.komoot.io', 'pasaje los aromos 98'),
    ]);

    $registros = [];
    Log::listen(function (MessageLogged $evento) use (&$registros): void {
        $registros[] = $evento->level.' '.$evento->message.' '.json_encode($evento->context);
    });

    expect(Geocoder::sugerencias('pasaje los aromos 98'))->toBe([]);

    $log = implode("\n", $registros);

    expect($registros)->not->toBe([])
        ->and($log)->not->toContain('aromos');
});

it('GeocoderNoDisponible no arrastra la dirección en su cadena de excepciones', function () {
    // La excepción está pensada para subir desde un job en cola y que la cola
    // reintente; cuando el job agota los intentos, Laravel la reporta con su
    // cadena de `previous` completa, en laravel.log y en GlitchTip. Un
    // `previous` con la URI de Guzzle es la dirección del vecino en el log.
    Http::fake([
        'nominatim.openstreetmap.org/*' => fn () => throw timeoutConLaDireccionEnLaUri(
            'nominatim.openstreetmap.org', 'Los Quintos 034, Graneros, Región de O\'Higgins, Chile',
        ),
    ]);

    try {
        Geocoder::buscarEstricto('Los Quintos 034');
        $this->fail('Tenía que lanzar.');
    } catch (GeocoderNoDisponible $e) {
        $mensajes = [];
        for ($actual = $e; $actual !== null; $actual = $actual->getPrevious()) {
            $mensajes[] = $actual::class.': '.$actual->getMessage();
        }

        expect(implode("\n", $mensajes))->not->toContain('Quintos');
    }
});
