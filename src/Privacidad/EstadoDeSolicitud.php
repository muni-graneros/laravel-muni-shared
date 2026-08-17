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
