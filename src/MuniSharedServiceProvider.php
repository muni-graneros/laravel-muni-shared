<?php

namespace Muni\Shared;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider del paquete compartido del ecosistema municipal.
 *
 * Hoy el paquete solo expone helpers estáticos (Geocoder), que no requieren
 * registro. Este provider queda como punto de enganche para cuando se muevan
 * aquí los bindings de dominio (p.ej. PersonaResolverInterface) tras normalizar
 * el DTO/contrato entre los sistemas consumidores.
 */
class MuniSharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindings compartidos futuros (PersonaResolver, etc.).
    }

    public function boot(): void
    {
        // Publicables/config compartida futura.
    }
}
