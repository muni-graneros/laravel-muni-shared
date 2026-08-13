<?php

namespace Muni\Shared\Privacidad;

/** Los derechos del titular: acceso, rectificación, supresión, oposición y portabilidad. */
enum TipoDeSolicitud: string
{
    case Acceso = 'acceso';
    case Rectificacion = 'rectificacion';
    case Supresion = 'supresion';
    case Oposicion = 'oposicion';
    case Portabilidad = 'portabilidad';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Acceso => 'Acceso',
            self::Rectificacion => 'Rectificación',
            self::Supresion => 'Supresión',
            self::Oposicion => 'Oposición',
            self::Portabilidad => 'Portabilidad',
        };
    }
}
