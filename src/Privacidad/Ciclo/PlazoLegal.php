<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * En qué punto del plazo legal está una solicitud.
 *
 * Una solicitud ya resuelta no tiene «vencida» ni «por vencer»: el plazo dejó de
 * importar el día que se cerró el caso.
 */
final class PlazoLegal
{
    /**
     * Cuántos días antes del vencimiento se avisa.
     *
     * Vive acá y no repetido en `Solicitud::scopePorVencer()`: dos umbrales
     * paralelos envejecen mal, y este es el número que decide si un caso aparece
     * o no en la bandeja de urgentes.
     */
    public const DIAS_POR_VENCER = 5;

    public static function de(Solicitud $solicitud): EstadoDePlazo
    {
        if ($solicitud->estado->estaResuelta()) {
            return EstadoDePlazo::Resuelta;
        }

        $dias = $solicitud->diasRestantes();

        if ($dias < 0) {
            return EstadoDePlazo::Vencida;
        }

        return $dias <= self::DIAS_POR_VENCER
            ? EstadoDePlazo::PorVencer
            : EstadoDePlazo::EnPlazo;
    }
}
