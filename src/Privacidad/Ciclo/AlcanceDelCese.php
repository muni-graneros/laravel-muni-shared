<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

/**
 * Qué deja de hacer ESTE sistema cuando un bloqueo queda vigente.
 *
 * La frase es la que el funcionario le repite al vecino, así que la escribe el
 * adoptante y no el paquete: el mapeo tratamiento→finalidad —qué pantalla, qué
 * CSV, qué correo y qué job dejan de tocar a esa persona— es propio de cada
 * sistema, y el módulo no lo conoce ni lo puede ejecutar.
 *
 * Que el default diga «no lo declaró» en vez de una frase tranquilizadora es
 * deliberado: recibir y resolver solicitudes es la SUPERFICIE del cumplimiento,
 * no el cumplimiento. Un sistema que herede el panel y no escriba su candado le
 * certificaría por escrito a un vecino un cese que no ocurre.
 */
final readonly class AlcanceDelCese
{
    public function __construct(private ?string $declarado = null) {}

    public function fueDeclarado(): bool
    {
        return $this->declarado !== null && trim($this->declarado) !== '';
    }

    public function texto(): string
    {
        return $this->fueDeclarado()
            ? (string) $this->declarado
            : 'Este sistema no declaró qué deja de hacer con los datos de esta persona cuando el bloqueo queda '
                .'vigente. Antes de decirle al titular que su tratamiento cesó, confirmarlo con informática: el bloqueo '
                .'queda anotado, pero que se respete depende de un candado que este sistema tiene que haber escrito.';
    }

    /**
     * Qué pasó con el bloqueo, que no es lo mismo según cómo se resolvió.
     *
     * Una oposición ACOGIDA no levanta el bloqueo: lo vuelve definitivo. Decir
     * lo contrario dejaría entender que el vecino quedó como antes, justo cuando
     * se le dio la razón.
     */
    public function efectoSobreElBloqueo(TipoDeSolicitud $tipo, EstadoDeSolicitud $resultado): string
    {
        $cesa = $resultado->esAcogida() && $tipo === TipoDeSolicitud::Oposicion;

        if (! $cesa) {
            return 'Si había un bloqueo por esta solicitud, el módulo lo levantó.';
        }

        return 'El bloqueo queda DEFINITIVO: el tratamiento cesa. '.$this->texto();
    }
}
