<?php

namespace Muni\Shared;

use Illuminate\Support\ServiceProvider;
use Muni\Shared\Console\MuniDocsCommand;

/**
 * Service provider del paquete compartido del ecosistema municipal.
 *
 * Expone helpers estáticos (Geocoder, RutHelper, SsoClaims…) y el comando
 * `muni:docs`, que genera la documentación técnica de CUALQUIER sistema del
 * ecosistema por introspección (BD, ER, estados, grafo de código, funcionalidades).
 */
class MuniSharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindings compartidos futuros (PersonaResolver, etc.).
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MuniDocsCommand::class]);
        }
    }
}
