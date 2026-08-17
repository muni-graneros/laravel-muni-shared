<?php

namespace Muni\Shared\Privacidad;

/**
 * Los tres desenlaces posibles de propagar un cambio al maestro de personas.
 *
 * Es enum con valor de texto porque estos nombres se escriben en la evidencia
 * (`privacidad_bitacora.datos`) y ahí tienen que sobrevivir a cualquier
 * refactor: una fila que dijera `2` no le explicaría nada a quien revise el
 * registro dentro de tres años.
 */
enum EstadoDePropagacion: string
{
    /** El maestro recibió el cambio y lo aceptó. Lo único que es éxito. */
    case Aceptada = 'aceptada';

    /** El maestro contestó que no. No se destruye ni se certifica nada. */
    case Rechazada = 'rechazada';

    /**
     * No se habló con el maestro, y estuvo bien no hablarle.
     *
     * El caso legítimo: el sistema declaró que no es modelo de lectura del
     * maestro (`SupresionSoloLocal`), o en tiempo de ejecución no hay a quién
     * hablarle para este titular. Lo que NO es: una forma cómoda de saltarse
     * la propagación —por eso exige un motivo escrito, ver
     * `ResultadoDePropagacion::noCorrespondia()`—.
     */
    case NoCorrespondia = 'no_correspondia';
}
