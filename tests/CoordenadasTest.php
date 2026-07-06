<?php

use Muni\Shared\Coordenadas;

it('parsea coordenadas válidas', function () {
    $r = Coordenadas::parse('-34.0664, -70.7297');
    expect($r)->not->toBeNull()
        ->and($r['lat'])->toBe(-34.0664)
        ->and($r['lng'])->toBe(-70.7297);
});

it('devuelve null con entrada inválida', function () {
    expect(Coordenadas::parse(null))->toBeNull()
        ->and(Coordenadas::parse('no-son-coords'))->toBeNull();
});
