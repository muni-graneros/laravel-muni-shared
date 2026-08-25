<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

/**
 * Con qué resultados se puede cerrar a mano una solicitud.
 *
 * A una rectificación y a una supresión se les quitan las DOS acogidas, no solo
 * la total: «acogida parcial» a mano sellaría una corrección o un cese sin
 * corregir ni suprimir nada, o sea nada más que el papel. Esas se acogen
 * ejecutando la acción correspondiente, que es la que escribe y propaga.
 */
final class ResultadosDisponibles
{
    /** @return array<string, string> */
    public static function para(TipoDeSolicitud $tipo): array
    {
        $estados = match ($tipo) {
            TipoDeSolicitud::Rectificacion, TipoDeSolicitud::Supresion => [EstadoDeSolicitud::Rechazada],
            default => [
                EstadoDeSolicitud::Acogida,
                EstadoDeSolicitud::AcogidaParcial,
                EstadoDeSolicitud::Rechazada,
            ],
        };

        $resultados = [];

        foreach ($estados as $estado) {
            $resultados[$estado->value] = $estado->etiqueta();
        }

        return $resultados;
    }

    public static function nota(TipoDeSolicitud $tipo): ?string
    {
        return match ($tipo) {
            TipoDeSolicitud::Rectificacion => 'Para acogerla, usa «Rectificar»: acoger sin corregir dejaría el dato como está.',
            TipoDeSolicitud::Supresion => 'Para acogerla, usa «Suprimir»: acoger sin suprimir sellaría un borrado que no ocurrió. '
                .'El rechazo sí se resuelve acá, con tu propio fundamento.',
            default => null,
        };
    }
}
