<?php

use Muni\Shared\Privacidad\EstadoDeSolicitud;

it('etiqueta cada estado en español, igual que TipoDeSolicitud lo hace con los tipos', function (EstadoDeSolicitud $estado, string $esperada) {
    expect($estado->etiqueta())->toBe($esperada);
})->with([
    [EstadoDeSolicitud::Recibida, 'Recibida'],
    [EstadoDeSolicitud::EnTramite, 'En trámite'],
    [EstadoDeSolicitud::Acogida, 'Acogida'],
    [EstadoDeSolicitud::AcogidaParcial, 'Acogida parcial'],
    [EstadoDeSolicitud::Rechazada, 'Rechazada'],
]);
