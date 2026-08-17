<?php

namespace Muni\Shared\Privacidad\Contratos;

use Muni\Shared\Privacidad\ResultadoDePropagacion;

/**
 * Qué tiene que pasar en el maestro de personas cuando un sistema suprime a un
 * titular.
 *
 * Las dos respuestas que ya se descartaron, porque las dos se probaron contra
 * un maestro real y las dos son incorrectas:
 *
 * 1. **No mandar nada.** La identidad completa del titular sigue viva en el
 *    registro federado y disponible para los otros siete sistemas por RUT. El
 *    municipio le respondería a la Agencia «suprimimos» y sería falso.
 * 2. **Mandar la persona anonimizada.** El maestro hace upsert por RUT y
 *    `ANON-{id}` es un RUT que no existía: crea una persona NUEVA y deja la
 *    real intacta. Verificado: 120 filas basura en 60 anonimizaciones.
 *
 * La tercera respuesta, que es la de este contrato: propagar una **supresión
 * del registro existente**, identificada por el documento que el titular tenía
 * ANTES de anonimizar —después no queda nada con qué encontrarlo—. Qué hace el
 * maestro con eso (borrar la fila, anonimizarla también, marcarla como
 * suprimida por solicitud del titular) es decisión del maestro; lo que este
 * contrato exige es que el sistema sepa que ocurrió antes de destruir lo suyo.
 *
 * Requisitos, los mismos que `PropagaRectificacion` y por el mismo motivo:
 *
 * - **Síncrono.** `ResultadoDePropagacion::aceptada()` significa «el maestro ya
 *   lo aceptó». Una implementación que despache un job en cola y devuelva esa
 *   respuesta no es válida: informa una supresión que todavía no ocurrió, y
 *   para cuando la cola falle el dato local ya no existe.
 * - **`rechazada()` cuando el maestro dice que no.** La retención aborta y no
 *   destruye.
 * - **Puede lanzar**, y lanzar se trata igual que `rechazada()`. El transporte
 *   del ecosistema (`SincronizarAlMaestro`) hace `$resp->throw()`, así que el
 *   rechazo llega casi siempre como excepción.
 * - **`noCorrespondia($motivo)` cuando no se habló con el maestro y estuvo bien
 *   no hablarle.** No es una forma educada de decir «no pude»: la supresión
 *   local sigue adelante. Es la respuesta para el sistema que SÍ tiene maestro
 *   pero en tiempo de ejecución decide no contactarlo (el ambiente no lo tiene
 *   configurado, este titular nunca estuvo allá). Existe porque su ausencia ya
 *   produjo el defecto: sin ella, un adoptante devolvía `true` sin contactar a
 *   nadie y el módulo destruía el dato local creyendo que el maestro lo había
 *   aceptado. El motivo es obligatorio y queda en la evidencia.
 *
 * Un sistema que NO es modelo de lectura del maestro enlaza `SupresionSoloLocal`
 * para declararlo explícitamente. No hay enlace por defecto a propósito: sin
 * declaración, `AplicarRetencion` se niega a ejecutar.
 */
interface PropagaSupresion
{
    /**
     * @param  string  $documento  el documento del titular ANTES de anonimizar
     */
    public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion;
}
