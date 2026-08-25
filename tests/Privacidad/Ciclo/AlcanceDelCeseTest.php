<?php

use Muni\Shared\Privacidad\Ciclo\AlcanceDelCese;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

it('sin declarar, dice que no se declaró en vez de tranquilizar', function () {
    $alcance = new AlcanceDelCese;

    expect($alcance->fueDeclarado())->toBeFalse()
        ->and($alcance->texto())->toContain('no declaró qué deja de hacer');
});

it('declarado, dice exactamente lo que el sistema deja de hacer', function () {
    $alcance = new AlcanceDelCese('Deja de aparecer en los listados de derivación y no recibe más avisos.');

    expect($alcance->fueDeclarado())->toBeTrue()
        ->and($alcance->texto())->toBe('Deja de aparecer en los listados de derivación y no recibe más avisos.');
});

it('una declaración en blanco no cuenta como declarada', function () {
    expect((new AlcanceDelCese('   '))->fueDeclarado())->toBeFalse();
});

it('acoger una oposición vuelve el bloqueo definitivo', function () {
    $aviso = (new AlcanceDelCese('El sistema deja de derivar sus requerimientos.'))
        ->efectoSobreElBloqueo(TipoDeSolicitud::Oposicion, EstadoDeSolicitud::Acogida);

    expect($aviso)->toContain('El bloqueo queda DEFINITIVO')
        ->and($aviso)->toContain('El sistema deja de derivar sus requerimientos.');
});

it('acoger parcialmente una oposición también hace cesar el tratamiento', function () {
    $aviso = (new AlcanceDelCese)
        ->efectoSobreElBloqueo(TipoDeSolicitud::Oposicion, EstadoDeSolicitud::AcogidaParcial);

    expect($aviso)->toContain('El bloqueo queda DEFINITIVO');
});

it('rechazar una oposición levanta el bloqueo', function () {
    $aviso = (new AlcanceDelCese)
        ->efectoSobreElBloqueo(TipoDeSolicitud::Oposicion, EstadoDeSolicitud::Rechazada);

    expect($aviso)->toBe('Si había un bloqueo por esta solicitud, el módulo lo levantó.');
});

it('acoger un acceso no vuelve definitivo ningún bloqueo', function () {
    $aviso = (new AlcanceDelCese)
        ->efectoSobreElBloqueo(TipoDeSolicitud::Acceso, EstadoDeSolicitud::Acogida);

    expect($aviso)->toBe('Si había un bloqueo por esta solicitud, el módulo lo levantó.');
});
