<?php

namespace Muni\Shared\Privacidad\Ciclo;

/**
 * El semáforo de plazo de una solicitud ARCOP.
 *
 * Es un estado, no un color: el panel de Filament lo pinta con su paleta y el
 * panel Blade con la suya, pero la regla de cuándo una solicitud está vencida es
 * una sola y es legal.
 */
enum EstadoDePlazo: string
{
    case Resuelta = 'resuelta';
    case Vencida = 'vencida';
    case PorVencer = 'por_vencer';
    case EnPlazo = 'en_plazo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Resuelta => 'Resuelta',
            self::Vencida => 'Vencida',
            self::PorVencer => 'Por vencer',
            self::EnPlazo => 'En plazo',
        };
    }
}
