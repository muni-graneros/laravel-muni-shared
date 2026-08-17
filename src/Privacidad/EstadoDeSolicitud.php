<?php

namespace Muni\Shared\Privacidad;

enum EstadoDeSolicitud: string
{
    case Recibida = 'recibida';
    case EnTramite = 'en_tramite';
    case Acogida = 'acogida';
    case AcogidaParcial = 'acogida_parcial';
    case Rechazada = 'rechazada';

    public function estaResuelta(): bool
    {
        return $this === self::Acogida
            || $this === self::AcogidaParcial
            || $this === self::Rechazada;
    }

    /**
     * Los estados de una solicitud que todavía está abierta.
     *
     * Se DERIVA de `estaResuelta()` en vez de listarse a mano: una lista
     * paralela envejece mal —basta que un ciclo agregue un estado para que las
     * dos digan cosas distintas—, y este módulo ya se equivocó así antes.
     *
     * @return array<int, self>
     */
    public static function pendientes(): array
    {
        return array_values(array_filter(self::cases(), fn (self $estado): bool => ! $estado->estaResuelta()));
    }

    /**
     * Si la resolución le dio la razón al titular, entera o en parte.
     *
     * `AcogidaParcial` cuenta como acogida y no como un punto medio: lo que
     * distingue a una acogida parcial es el ALCANCE de lo concedido, no que se
     * haya concedido menos convicción. Tratarla como si fuera un rechazo es lo
     * que hacía que acoger parcialmente una oposición levantara el bloqueo
     * sobre las finalidades en las que el municipio le acababa de dar la razón.
     */
    public function esAcogida(): bool
    {
        return $this === self::Acogida || $this === self::AcogidaParcial;
    }

    /**
     * Sin esto, cada panel que lista solicitudes escribe sus propias
     * etiquetas en español, y cinco sistemas terminan nombrando el mismo
     * estado de cinco formas distintas en documentos que un titular lee.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Recibida => 'Recibida',
            self::EnTramite => 'En trámite',
            self::Acogida => 'Acogida',
            self::AcogidaParcial => 'Acogida parcial',
            self::Rechazada => 'Rechazada',
        };
    }
}
