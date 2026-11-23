<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Muni\Shared\Geocoder;

/**
 * Feature de Photon ficticia (GeoJSON: [lon, lat]).
 *
 * @param  array<string, string>  $extra
 * @return array<string, mixed>
 */
function featureComuna(string $calle, string $numero, array $extra): array
{
    return [
        'properties' => array_merge(['countrycode' => 'CL', 'street' => $calle, 'housenumber' => $numero], $extra),
        'geometry' => ['coordinates' => [-70.7297, -34.0664]],
    ];
}

/** @return array<string, mixed> */
function photonMezclado(): array
{
    return ['features' => [
        featureComuna('Calle Uno', '10', ['city' => 'Graneros', 'county' => 'Cachapoal']),
        featureComuna('Calle Uno', '10', ['city' => 'Machalí', 'county' => 'Cachapoal']),
        featureComuna('Calle Dos', '20', ['district' => 'Graneros']),
        featureComuna('Calle Tres', '30', ['county' => 'Graneros']),
    ]];
}

/** @return list<string> */
function etiquetas(array $sugerencias): array
{
    return array_map(fn (array $s): string => $s['label'], $sugerencias);
}

it('con comuna devuelve solo sugerencias de esa comuna y descarta las de Machalí', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(photonMezclado())]);

    expect(etiquetas(Geocoder::sugerencias('calle', 6, 'Graneros')))
        ->toBe(['Calle Uno 10, Graneros', 'Calle Dos 20, Graneros']);
});

it('compara la comuna sin tildes, sin mayúsculas y sin espacios de borde', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(photonMezclado())]);

    expect(etiquetas(Geocoder::sugerencias('calle', 6, '  machali ')))->toBe(['Calle Uno 10, Machalí'])
        ->and(etiquetas(Geocoder::sugerencias('calle', 6, 'GRANEROS')))->toHaveCount(2);
});

it('la provincia (county) no cuenta como comuna', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(photonMezclado())]);

    expect(etiquetas(Geocoder::sugerencias('calle', 6, 'Graneros')))->not->toContain('Calle Tres 30, Graneros');
});

it('sin comuna se comporta como antes: no filtra, no manda bbox y pide el triple', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(photonMezclado())]);

    expect(Geocoder::sugerencias('calle'))->toHaveCount(4);

    Http::assertSent(function (Request $r): bool {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return ! array_key_exists('bbox', $q) && $q['limit'] === '18';
    });
});

it('una comuna vacía equivale a no pasarla', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(photonMezclado())]);

    expect(Geocoder::sugerencias('calle', 6, '   '))->toHaveCount(4);
});

it('con Graneros manda el recuadro de la comuna al host fijo, con el User-Agent y el sesgo', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(photonMezclado())]);

    Geocoder::sugerencias('calle', 6, 'Graneros');

    Http::assertSent(function (Request $r): bool {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return str_starts_with($r->url(), 'https://photon.komoot.io/api/?')
            && $r->hasHeader('User-Agent', 'SistemasMunicipalesGraneros/1.0 (https://municipalidadgraneros.cl)')
            && $q['bbox'] === '-70.9,-34.2,-70.6,-33.95'
            && $q['limit'] === '30'
            && isset($q['lat'], $q['lon']);
    });
});

it('una comuna sin recuadro conocido filtra igual pero no manda bbox', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(photonMezclado())]);

    expect(etiquetas(Geocoder::sugerencias('calle', 6, 'Machalí')))->toBe(['Calle Uno 10, Machalí']);

    Http::assertSent(function (Request $r): bool {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return ! array_key_exists('bbox', $q);
    });
});

it('respeta el límite después de filtrar', function () {
    $features = [];
    for ($i = 1; $i <= 10; $i++) {
        $features[] = featureComuna('Calle Machalí', (string) $i, ['city' => 'Machalí']);
        $features[] = featureComuna('Calle Graneros', (string) $i, ['city' => 'Graneros']);
    }
    Http::fake(['photon.komoot.io/*' => Http::response(['features' => $features])]);

    $s = Geocoder::sugerencias('calle', 3, 'Graneros');

    expect($s)->toHaveCount(3)
        ->and(etiquetas($s))->each->toEndWith(', Graneros');
});

it('devuelve lista vacía cuando nada es de la comuna, y la cachea aparte de la consulta sin comuna', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(['features' => [
        featureComuna('Calle Uno', '10', ['city' => 'Machalí']),
    ]])]);

    expect(Geocoder::sugerencias('calle uno', 6, 'Graneros'))->toBe([])
        ->and(Geocoder::sugerencias('calle uno', 6, 'Graneros'))->toBe([]);
    Http::assertSentCount(1);

    expect(Geocoder::sugerencias('calle uno'))->toHaveCount(1);
    Http::assertSentCount(2);
});

it('un fallo de red con comuna devuelve vacío y no loguea el texto ni la comuna', function () {
    $GLOBALS['__log_comuna'] = [];
    Log::listen(function (MessageLogged $e): void {
        $GLOBALS['__log_comuna'][] = $e->message.' '.json_encode($e->context);
    });
    Http::fake(['photon.komoot.io/*' => fn () => throw new ConnectionException('timeout calle secreta 77')]);

    expect(Geocoder::sugerencias('calle secreta 77', 6, 'Graneros'))->toBe([]);

    $log = implode("\n", $GLOBALS['__log_comuna']);
    expect($log)->not->toBe('')
        ->and($log)->not->toContain('secreta')
        ->and($log)->not->toContain('Graneros');
});
