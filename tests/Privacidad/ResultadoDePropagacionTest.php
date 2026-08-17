<?php

use Muni\Shared\Privacidad\EstadoDePropagacion;
use Muni\Shared\Privacidad\PropagacionInvalida;
use Muni\Shared\Privacidad\ResultadoDePropagacion;

/**
 * El tercer estado de la propagación al maestro de personas.
 *
 * Hasta acá `propagar()` devolvía `bool` y tenía dos respuestas: el maestro
 * aceptó, o el maestro rechazó. Faltaba la que la adopción real ya estaba
 * diciendo con una mentira: «no propagué, y estuvo bien no propagar». Sin
 * forma de decirla, un adoptante devolvía `true` sin haber contactado a nadie
 * y el módulo destruía el dato local creyendo que el maestro lo había
 * aceptado.
 */
it('el maestro aceptó: el único estado que puede leerse como éxito', function () {
    $resultado = ResultadoDePropagacion::aceptada();

    expect($resultado->estado)->toBe(EstadoDePropagacion::Aceptada)
        ->and($resultado->loAceptoElMaestro())->toBeTrue()
        ->and($resultado->laRechazoElMaestro())->toBeFalse()
        ->and($resultado->seHabloConElMaestro())->toBeTrue()
        ->and($resultado->motivo)->toBeNull();
});

it('el maestro rechazó', function () {
    $resultado = ResultadoDePropagacion::rechazada();

    expect($resultado->estado)->toBe(EstadoDePropagacion::Rechazada)
        ->and($resultado->loAceptoElMaestro())->toBeFalse()
        ->and($resultado->laRechazoElMaestro())->toBeTrue()
        // Sí se habló: el maestro contestó que no.
        ->and($resultado->seHabloConElMaestro())->toBeTrue();
});

it('no correspondía propagar: no es éxito, y no se puede confundir con él', function () {
    $resultado = ResultadoDePropagacion::noCorrespondia('Este ambiente no tiene maestro de personas configurado.');

    expect($resultado->estado)->toBe(EstadoDePropagacion::NoCorrespondia)
        // Lo que fija este test: NO es aceptación. Quien pregunte «¿lo aceptó
        // el maestro?» tiene que recibir false, aunque la operación local
        // pueda seguir.
        ->and($resultado->loAceptoElMaestro())->toBeFalse()
        // Y tampoco es un rechazo: nada que reintentar, nada que abortar.
        ->and($resultado->laRechazoElMaestro())->toBeFalse()
        ->and($resultado->seHabloConElMaestro())->toBeFalse()
        ->and($resultado->motivo)->toBe('Este ambiente no tiene maestro de personas configurado.');
});

it('no correspondía sin motivo no se acepta: sería el mismo cheque en blanco con otro nombre', function () {
    // Sin motivo obligatorio, el tercer estado se vuelve la salida cómoda para
    // saltarse el maestro —exactamente el defecto que vino a arreglar—, y la
    // evidencia diría «no se propagó» sin poder responder por qué.
    expect(fn () => ResultadoDePropagacion::noCorrespondia('   '))
        ->toThrow(PropagacionInvalida::class, 'motivo');
});

it('la evidencia distingue los tres estados, que es donde tiene que verse', function () {
    expect(ResultadoDePropagacion::aceptada()->paraEvidencia())
        ->toBe(['estado' => 'aceptada', 'motivo' => null])
        ->and(ResultadoDePropagacion::rechazada()->paraEvidencia())
        ->toBe(['estado' => 'rechazada', 'motivo' => null])
        ->and(ResultadoDePropagacion::noCorrespondia('El titular nunca estuvo en el maestro.')->paraEvidencia())
        ->toBe(['estado' => 'no_correspondia', 'motivo' => 'El titular nunca estuvo en el maestro.']);
});
