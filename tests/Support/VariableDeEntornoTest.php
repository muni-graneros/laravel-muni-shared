<?php

declare(strict_types=1);

use Muni\Shared\Support\VariableDeEntorno;

it('acepta enteros reales y cadenas escritas exactamente como enteros', function () {
    expect(VariableDeEntorno::entero(-7, 41))->toBe(-7)
        ->and(VariableDeEntorno::entero('0', 41))->toBe(0)
        ->and(VariableDeEntorno::entero('007', 41))->toBe(7)
        ->and(VariableDeEntorno::entero('-12', 41))->toBe(-12);
});

it('rechaza booleanos, decimales, texto y formatos no exactos', function () {
    foreach ([true, false, 1.5, '1.5', '1e2', 'texto', '', ' ', ' 7 ', null, []] as $valor) {
        expect(VariableDeEntorno::entero($valor, 41))
            ->toBe(41, 'valor='.var_export($valor, true));
    }
});

it('enteroPositivo devuelve la omisión para valores menores que uno', function () {
    foreach ([true, false, 0, -1, '0', '-1', '1.5', 'texto', null] as $valor) {
        expect(VariableDeEntorno::enteroPositivo($valor, 41))
            ->toBe(41, 'valor='.var_export($valor, true));
    }

    expect(VariableDeEntorno::enteroPositivo(1, 41))->toBe(1)
        ->and(VariableDeEntorno::enteroPositivo('007', 41))->toBe(7);
});
