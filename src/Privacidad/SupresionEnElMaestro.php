<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

/**
 * La mitad federada de cualquier supresión: qué se le exige al sistema
 * adoptante y qué pasa si el maestro de personas no acepta.
 *
 * Está en su propia clase porque hay DOS caminos que destruyen a un titular
 * —`AplicarRetencion`, por vencimiento del plazo, y `Supresiones`, a petición
 * del titular— y las dos exigencias tienen que ser exactamente la misma. La
 * alternativa era repetir la comprobación y sus dos mensajes en cada servicio,
 * que es la forma en que este módulo ya se equivocó antes: dos copias de una
 * garantía divergen en cuanto alguien arregla una sola.
 *
 * Las dos exigencias, con su motivo:
 *
 * 1. **Declarar.** Sin un enlace de `PropagaSupresion` no se empieza. No hay
 *    default a propósito (ver el docblock del contrato): un sistema que no
 *    declaró qué pasa en el registro federado destruiría el dato local y
 *    dejaría la identidad viva y consultable por RUT para los otros siete.
 * 2. **Propagar antes de destruir.** Primero el maestro y después lo local: un
 *    rechazo del maestro se resuelve reintentando, mientras que un archivo
 *    borrado no lo devuelve ningún rollback. El peor caso queda siendo un
 *    titular suprimido en el maestro y todavía completo acá, que el siguiente
 *    intento vuelve a cubrir.
 */
final class SupresionEnElMaestro
{
    /**
     * @throws SupresionNoPropagada si el sistema no declaró qué pasa en el
     *                              maestro al suprimir a un titular
     */
    public function exigirDeclaracion(): void
    {
        if (app()->bound(PropagaSupresion::class)) {
            return;
        }

        throw new SupresionNoPropagada(
            'El sistema no declaró qué debe pasar en el maestro de personas al suprimir a un titular. '
            .'Enlazar Contratos\PropagaSupresion con la implementación que propaga la supresión al maestro, '
            .'o con SupresionSoloLocal si este sistema no es modelo de lectura del maestro.',
        );
    }

    /**
     * @param  string  $documento  el del titular ANTES de anonimizar: después no
     *                             queda nada con qué encontrarlo en el maestro
     *
     * @throws SupresionNoPropagada si no hay declaración, o si el maestro
     *                              rechaza la supresión
     */
    public function propagar(TitularDeDatos $titular, string $documento): void
    {
        // Se repite la comprobación de arriba aunque quien llama ya la haya
        // hecho al empezar: este es el único punto por el que se destruye, y
        // una comprobación lejos del efecto envejece mal.
        $this->exigirDeclaracion();

        if (! app(PropagaSupresion::class)->propagar($titular, $documento)) {
            throw new SupresionNoPropagada(
                'El maestro de personas rechazó la supresión: no se destruye el dato local, '
                .'porque la identidad seguiría viva y consultable por RUT en el registro federado.',
            );
        }
    }
}
