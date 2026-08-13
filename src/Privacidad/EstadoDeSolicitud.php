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
}
