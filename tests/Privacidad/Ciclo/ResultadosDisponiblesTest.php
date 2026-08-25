<?php

use Muni\Shared\Privacidad\Ciclo\ResultadosDisponibles;
use Muni\Shared\Privacidad\TipoDeSolicitud;

it('un acceso se puede acoger, acoger en parte o rechazar', function () {
    expect(ResultadosDisponibles::para(TipoDeSolicitud::Acceso))->toBe([
        'acogida' => 'Acogida',
        'acogida_parcial' => 'Acogida parcial',
        'rechazada' => 'Rechazada',
    ]);
});

it('una rectificación solo se puede rechazar a mano', function () {
    expect(ResultadosDisponibles::para(TipoDeSolicitud::Rectificacion))->toBe([
        'rechazada' => 'Rechazada',
    ]);
});

it('una supresión solo se puede rechazar a mano', function () {
    expect(ResultadosDisponibles::para(TipoDeSolicitud::Supresion))->toBe([
        'rechazada' => 'Rechazada',
    ]);
});

it('explica por dónde se acoge una rectificación', function () {
    expect(ResultadosDisponibles::nota(TipoDeSolicitud::Rectificacion))
        ->toContain('acoger sin corregir dejaría el dato como está');
});

it('explica por dónde se acoge una supresión', function () {
    expect(ResultadosDisponibles::nota(TipoDeSolicitud::Supresion))
        ->toContain('sellaría un borrado que no ocurrió');
});

it('no molesta con notas donde no hacen falta', function () {
    expect(ResultadosDisponibles::nota(TipoDeSolicitud::Oposicion))->toBeNull();
});
