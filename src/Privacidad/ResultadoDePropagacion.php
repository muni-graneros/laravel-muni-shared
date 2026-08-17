<?php

namespace Muni\Shared\Privacidad;

/**
 * Qué pasó cuando el sistema le llevó al maestro de personas una rectificación
 * o una supresión.
 *
 * Reemplaza al `bool` que devolvían `PropagaRectificacion::propagar()` y
 * `PropagaSupresion::propagar()`, y el motivo es un defecto medido, no una
 * preferencia de estilo: con dos respuestas —aceptó / rechazó— un sistema que
 * en tiempo de ejecución decide NO hablarle al maestro no tiene forma de
 * decirlo sin mentir. En la adopción de `discapacidad-graneros`,
 * `PropagaSupresionAlMaestro` devolvía `true` sin contactar a nadie cuando el
 * driver de la API no era `http`, así que el módulo destruía el dato local
 * creyendo que el maestro había aceptado la supresión, mientras la identidad
 * seguía viva y consultable por RUT para los otros siete sistemas.
 *
 * Los tres estados están en `EstadoDePropagacion`. Lo que esta clase agrega es
 * lo que un enum solo no puede llevar: **el motivo, obligatorio en el tercer
 * estado**. Sin él, «no correspondía propagar» sería el mismo cheque en blanco
 * de antes con un nombre nuevo, y la evidencia no podría responder por qué no
 * se propagó.
 *
 * ## Qué se puede escribir en el motivo, y qué no
 *
 * El motivo viaja a `privacidad_bitacora.datos`, que **no está cifrada**. Rige
 * ahí la misma invariante que en el resto del módulo: nombres de cosas, ids y
 * hechos del sistema, **nunca datos del titular**. «El maestro no está
 * configurado en este ambiente» sí; «Rocío Paredes no estaba en el maestro»
 * no. El módulo no puede comprobarlo —el texto lo escribe el adoptante— y por
 * eso queda dicho acá y anotado como residuo en el spec de pendientes.
 */
final readonly class ResultadoDePropagacion
{
    private function __construct(
        public EstadoDePropagacion $estado,
        public ?string $motivo,
    ) {}

    /**
     * El maestro recibió el cambio y lo aceptó.
     *
     * Es la única respuesta con la que el municipio puede certificar por
     * escrito que el registro federado quedó corregido o dado de baja.
     */
    public static function aceptada(): self
    {
        return new self(EstadoDePropagacion::Aceptada, null);
    }

    /**
     * El maestro contestó que no.
     *
     * No lleva motivo a propósito: el texto de un rechazo lo redacta el otro
     * lado —un cuerpo de error HTTP, por ejemplo— y ahí puede venir el dato
     * del titular que este módulo existe para no duplicar. Lo que el módulo
     * escribe en la evidencia es el hecho del rechazo, igual que `Rectificaciones`
     * guarda la clase de la excepción y nunca su mensaje.
     */
    public static function rechazada(): self
    {
        return new self(EstadoDePropagacion::Rechazada, null);
    }

    /**
     * No se habló con el maestro, y estuvo bien no hablarle.
     *
     * @param  string  $motivo  por qué no correspondía, en términos del SISTEMA
     *                          y nunca del titular (ver el docblock de la clase).
     *                          Es obligatorio y no puede ir en blanco.
     *
     * @throws PropagacionInvalida si el motivo viene vacío
     */
    public static function noCorrespondia(string $motivo): self
    {
        if (trim($motivo) === '') {
            throw new PropagacionInvalida(
                'Un «no correspondía propagar» necesita motivo escrito: sin él, la evidencia dice que '
                .'no se habló con el maestro de personas y no puede responder por qué, que es exactamente '
                .'lo que hay que poder mostrar cuando el titular pregunte por qué sus datos siguen allá.',
            );
        }

        return new self(EstadoDePropagacion::NoCorrespondia, $motivo);
    }

    /**
     * Lo único que significa «el maestro ya lo tiene».
     *
     * Existe con este nombre para que el sitio que consume la respuesta no
     * pueda confundir el tercer estado con el éxito: un `if ($resultado)` sobre
     * el bool viejo daba `true` para las dos cosas.
     */
    public function loAceptoElMaestro(): bool
    {
        return $this->estado === EstadoDePropagacion::Aceptada;
    }

    /** El maestro dijo que no: se aborta y no se destruye nada local. */
    public function laRechazoElMaestro(): bool
    {
        return $this->estado === EstadoDePropagacion::Rechazada;
    }

    /**
     * Si hubo conversación con el maestro, haya terminado como haya terminado.
     *
     * Sirve para lo que el módulo sí puede afirmar por su cuenta: cuántas de
     * las supresiones de una corrida se hicieron sin que nadie hablara con el
     * registro federado.
     */
    public function seHabloConElMaestro(): bool
    {
        return $this->estado !== EstadoDePropagacion::NoCorrespondia;
    }

    /**
     * La forma en que esto queda escrito en la evidencia.
     *
     * Va como sub-arreglo y no como dos claves sueltas para que las filas de la
     * bitácora se puedan leer y comparar de una: `datos->propagacion->estado`
     * es la misma ruta en `retencion.aplicada`, `supresion.aplicada` y
     * `rectificacion.aplicada`.
     *
     * @return array{estado: string, motivo: ?string}
     */
    public function paraEvidencia(): array
    {
        return ['estado' => $this->estado->value, 'motivo' => $this->motivo];
    }
}
