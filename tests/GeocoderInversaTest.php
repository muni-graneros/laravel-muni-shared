<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Muni\Shared\Geocoder;

/** @return array<string, mixed> */
function respuestaInversa(string $pais = 'cl'): array
{
    return [
        'name' => '',
        'display_name' => 'Avenida Central 123, Graneros, Región de O\'Higgins, Chile',
        'address' => [
            'road' => 'Avenida Central', 'house_number' => '123', 'town' => 'Graneros', 'country_code' => $pais,
        ],
    ];
}

/** @return list<string> */
function escucharLog(): array
{
    $GLOBALS['__log_inversa'] = [];
    Log::listen(function (MessageLogged $e): void {
        $GLOBALS['__log_inversa'][] = $e->level.' '.$e->message.' '.json_encode($e->context);
    });

    return [];
}

it('devuelve calle, número y comuna cuando Nominatim responde', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(respuestaInversa())]);

    expect(Geocoder::inversa(-34.066412, -70.729734))->toBe([
        'dir' => 'Avenida Central 123',
        'label' => 'Avenida Central 123, Graneros',
        'comuna' => 'Graneros',
    ]);
});

it('a Nominatim solo viajan las coordenadas redondeadas a 4 decimales y los parámetros fijos', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(respuestaInversa())]);

    Geocoder::inversa(-34.066412, -70.729734);

    Http::assertSent(function (Request $r): bool {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        ksort($q);

        return str_starts_with($r->url(), 'https://nominatim.openstreetmap.org/reverse?')
            && $r->hasHeader('User-Agent', 'SistemasMunicipalesGraneros/1.0 (https://municipalidadgraneros.cl)')
            && array_keys($q) === ['accept-language', 'addressdetails', 'format', 'lat', 'lon', 'zoom']
            && $q['lat'] === '-34.0664'
            && $q['lon'] === '-70.7297';
    });
});

it('devuelve null sin lanzar ante un timeout', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => fn () => throw new ConnectionException('timeout')]);

    expect(Geocoder::inversa(-34.0664, -70.7297))->toBeNull();
});

it('devuelve null ante un 429 de Nominatim', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response(null, 429)]);

    expect(Geocoder::inversa(-34.0664, -70.7297))->toBeNull();
});

it('devuelve null ante una respuesta vacía o sin dirección', function (mixed $cuerpo) {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response($cuerpo)]);

    expect(Geocoder::inversa(-34.0664, -70.7297))->toBeNull();
})->with([
    'vacía' => [[]],
    'error de Nominatim' => [['error' => 'Unable to geocode']],
    'sin calle ni nombre' => [['name' => '', 'address' => ['town' => 'Graneros', 'country_code' => 'cl']]],
]);

it('devuelve null fuera de Chile', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response(respuestaInversa('ar'))]);

    expect(Geocoder::inversa(-32.8895, -68.8458))->toBeNull();
});

it('no sale a la red con coordenadas inválidas', function (float $lat, float $lng) {
    Http::fake();

    expect(Geocoder::inversa($lat, $lng))->toBeNull();
    Http::assertNothingSent();
})->with([
    'latitud > 90' => [91.0, 0.0],
    'latitud < -90' => [-90.5, 0.0],
    'longitud > 180' => [0.0, 180.1],
    'longitud < -180' => [0.0, -181.0],
    'NAN' => [NAN, 0.0],
    'INF' => [0.0, INF],
]);

it('un acierto queda en caché: la segunda consulta no sale a la red', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response(respuestaInversa())]);

    Geocoder::inversa(-34.06641, -70.72973);
    $segunda = Geocoder::inversa(-34.06642, -70.72974); // mismo punto a 4 decimales

    expect($segunda['dir'])->toBe('Avenida Central 123');
    Http::assertSentCount(1);
});

it('un fallo no queda en caché: la siguiente consulta vuelve a preguntar', function () {
    Http::fakeSequence('nominatim.openstreetmap.org/*')
        ->push(null, 503)
        ->push(respuestaInversa());

    expect(Geocoder::inversa(-34.0664, -70.7297))->toBeNull()
        ->and(Geocoder::inversa(-34.0664, -70.7297)['dir'])->toBe('Avenida Central 123');
    Http::assertSentCount(2);
});

it('respeta el límite compartido de Nominatim sin salir a la red', function () {
    Http::fake();
    for ($i = 0; $i < 60; $i++) {
        RateLimiter::hit('geocoder:nominatim', 60);
    }

    expect(Geocoder::inversa(-34.0664, -70.7297))->toBeNull();
    Http::assertNothingSent();
});

it('los fallos se loguean sin las coordenadas', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out for https://nominatim.openstreetmap.org/reverse?lat=-34.0664&lon=-70.7297&format=jsonv2',
    )]);
    escucharLog();

    Geocoder::inversa(-34.0664, -70.7297);

    Http::fake(['nominatim.openstreetmap.org/*' => Http::response(null, 429)]);
    Geocoder::inversa(-34.0700, -70.7300);

    $log = implode("\n", $GLOBALS['__log_inversa']);
    expect($GLOBALS['__log_inversa'])->not->toBe([])
        ->and($log)->toContain('ConnectionException')
        ->and($log)->not->toContain('34.06')
        ->and($log)->not->toContain('70.72')
        ->and($log)->not->toContain('34.07')
        ->and($log)->not->toContain('70.73')
        ->and($log)->not->toContain('reverse?');
});
