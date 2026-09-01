<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
