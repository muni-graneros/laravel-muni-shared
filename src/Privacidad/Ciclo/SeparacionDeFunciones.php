<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * Aviso —no prohibición— cuando quien va a resolver es quien recibió.
 *
 * El módulo permite que sean la misma persona: en un municipio chico el mismo
 * funcionario atiende el mesón y resuelve, y eso lo decide el municipio, no este
 * código. Lo que sí hace es que la coincidencia se vea justo en el momento de
 * resolver.
 *
 * Quién resuelve llega por parámetro y no de `auth()`: el núcleo no supone que
 * hay una sesión web —una resolución puede venir de un comando— y así la regla
 * se puede probar sin montar autenticación.
 */
final class SeparacionDeFunciones
{
    public static function advertencia(Solicitud $solicitud, int|string|null $quienResuelve): ?string
    {
        $registro = $solicitud->getAttribute('user_registro_id');

        if ($registro === null || $quienResuelve === null) {
            return null;
        }

        if ((string) $registro !== (string) $quienResuelve) {
            return null;
        }

        return 'Esta solicitud la recibiste tú. Resolverla tú mismo concentra la recepción y la resolución en '
            .'una sola persona: si hay alguien más que pueda resolverla, mejor que la resuelva esa persona.';
    }
}
