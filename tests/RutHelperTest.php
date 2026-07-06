<?php

use Muni\Shared\RutHelper;

it('valida un RUT correcto y rechaza uno incorrecto', function () {
    expect(RutHelper::validate('11.111.111-1'))->toBeTrue()
        ->and(RutHelper::validate('11.111.111-2'))->toBeFalse();
});

it('normaliza y formatea RUT en distintos formatos', function () {
    expect(RutHelper::normalize('12.345.678-5'))->toBe('12345678-5')
        ->and(RutHelper::normalize('123456785'))->toBe('12345678-5')
        ->and(RutHelper::format('12345678-5'))->toBe('12.345.678-5');
});

it('calcula el dígito verificador', function () {
    expect(RutHelper::calcularDv('12345678'))->toBe('5');
});
