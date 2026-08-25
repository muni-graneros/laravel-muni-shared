<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

/**
 * Si de ESTA solicitud se puede entregar la copia de los datos del titular.
 *
 * Vive en el módulo porque son tres reglas legales, no tres condiciones de
 * pantalla, y porque el panel que no las aplique ofrece un botón que revienta:
 * `ExportacionDeDatos::paraSolicitud()` se niega igual, pero recién cuando el
 * funcionario ya lo apretó delante del vecino.
 */
final class EntregaDeCopia
{
    public static function procede(Solicitud $solicitud): bool
    {
        return self::porQueNo($solicitud) === null;
    }

    /** El motivo por el que NO procede, o `null` si procede. */
    public static function porQueNo(Solicitud $solicitud): ?string
    {
        // Solo acceso y portabilidad dan derecho a la copia completa. Una
        // solicitud de supresión u oposición no habilita a nadie a llevarse el
        // expediente: tomar el tipo sin verificarlo convertiría cualquier
        // solicitud registrada en una llave universal.
        if (! in_array($solicitud->tipo, [TipoDeSolicitud::Acceso, TipoDeSolicitud::Portabilidad], true)) {
            return "La solicitud #{$solicitud->getKey()} es de tipo «{$solicitud->tipo->etiqueta()}»: "
                .'solo el acceso y la portabilidad dan derecho a la copia de los datos.';
        }

        // Entregar la copia sobre una solicitud rechazada sería exactamente la
        // comunicación de datos que la resolución acaba de negar.
        if ($solicitud->estado === EstadoDeSolicitud::Rechazada) {
            return "La solicitud #{$solicitud->getKey()} fue rechazada: entregar igual la copia sería la "
                .'comunicación de datos que esa resolución negó.';
        }

        if (! $solicitud->titular instanceof TitularDeDatos) {
            return "La solicitud #{$solicitud->getKey()} no tiene un titular vigente del que exportar datos.";
        }

        return null;
    }
}
