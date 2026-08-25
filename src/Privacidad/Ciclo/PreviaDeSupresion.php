<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\Supresiones;

/**
 * Lo que el funcionario ve ANTES de suprimir: hasta dónde llega el derecho de
 * este titular según el RAT.
 *
 * `Supresiones::evaluar()` no escribe nada —existe justamente para poder mostrar
 * esto antes de resolver— y su explicación cita la norma y el plazo, que es lo
 * que el funcionario tiene que copiar en el fundamento.
 */
final class PreviaDeSupresion
{
    public static function de(Solicitud $solicitud): ?string
    {
        $titular = $solicitud->titular;

        if (! $titular instanceof TitularDeDatos) {
            return null;
        }

        return app(Supresiones::class)->evaluar($titular)->explicacion();
    }

    /** La previa, junto con el aviso de separación de funciones si corresponde. */
    public static function antesDeSuprimir(Solicitud $solicitud, int|string|null $quienResuelve): string
    {
        return trim(implode("\n\n", array_filter([
            SeparacionDeFunciones::advertencia($solicitud, $quienResuelve),
            self::de($solicitud),
        ])));
    }
}
