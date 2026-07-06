<?php

use Illuminate\Support\Facades\Http;
use Muni\Shared\Geocoder;

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
