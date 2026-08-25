<?php

use Muni\Shared\Privacidad\Ciclo\SeparacionDeFunciones;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

function solicitudRecibidaPor(?int $userId): Solicitud
{
    $solicitud = new Solicitud([
        'sistema' => 'discapacidad',
        'tipo' => TipoDeSolicitud::Acceso,
        'estado' => EstadoDeSolicitud::Recibida,
    ]);
    $solicitud->setAttribute('user_registro_id', $userId);

    return $solicitud;
}

it('no advierte nada cuando resuelve otra persona', function () {
    expect(SeparacionDeFunciones::advertencia(solicitudRecibidaPor(7), 9))->toBeNull();
});

it('no advierte nada cuando no se sabe quién la recibió', function () {
    expect(SeparacionDeFunciones::advertencia(solicitudRecibidaPor(null), 9))->toBeNull();
});

it('advierte cuando quien resuelve es quien recibió', function () {
    expect(SeparacionDeFunciones::advertencia(solicitudRecibidaPor(7), 7))
        ->toContain('Esta solicitud la recibiste tú');
});

it('no confunde a un invitado sin sesión con quien recibió', function () {
    expect(SeparacionDeFunciones::advertencia(solicitudRecibidaPor(null), null))->toBeNull();
});
