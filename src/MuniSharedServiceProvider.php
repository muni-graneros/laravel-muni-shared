<?php

namespace Muni\Shared;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Muni\Shared\Console\ConfigurarCorreoCommand;
use Muni\Shared\Console\MuniDocsCommand;
use Muni\Shared\Console\ProbarCorreoCommand;
use Muni\Shared\Correo\TransporteGraph;

/**
 * Service provider del paquete compartido del ecosistema municipal.
 *
 * Expone helpers estáticos (Geocoder, RutHelper, SsoClaims…), el comando
 * `muni:docs`, que genera la documentación técnica de CUALQUIER sistema del
 * ecosistema por introspección, y el envío de correo por Microsoft Graph.
 */
class MuniSharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El bloque del mailer se fusiona acá, así que ningún sistema del
        // ecosistema tiene que tocar su config/mail.php: alcanza con poner
        // MAIL_MAILER=graph y las credenciales en su .env. Va en register() y
        // no en boot() porque la configuración tiene que estar completa antes
        // de que alguien resuelva el mailer.
        $this->mergeConfigFrom(__DIR__.'/../config/correo-graph.php', 'mail.mailers.graph');
        $this->mergeConfigFrom(__DIR__.'/../config/privacidad.php', 'privacidad');
    }

    public function boot(): void
    {
        // Las migraciones se cargan y no se publican: así, actualizar el paquete
        // propaga el esquema a los 8 sistemas con un `migrate`, sin un paso de
        // publicación por repo que alguien va a olvidar.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/privacidad.php' => config_path('privacidad.php'),
            ], 'privacidad-config');
        }

        $this->registrarCorreoPorGraph();

        if ($this->app->runningInConsole()) {
            $this->commands([
                MuniDocsCommand::class,
                ProbarCorreoCommand::class,
                ConfigurarCorreoCommand::class,
            ]);
        }
    }

    /**
     * El transporte propio de correo por Microsoft Graph.
     *
     * Va acá y no en la configuración porque Laravel resuelve los transportes
     * por nombre al momento de enviar, no al leer el archivo de configuración.
     */
    private function registrarCorreoPorGraph(): void
    {
        Mail::extend('graph', function (array $config): TransporteGraph {
            foreach (['tenant', 'cliente', 'secreto', 'remitente'] as $clave) {
                if (! isset($config[$clave]) || ! is_string($config[$clave]) || trim($config[$clave]) === '') {
                    throw new \RuntimeException(
                        "Falta {$clave} en la configuración del correo por Graph. "
                        .'Se carga con: php artisan correo:configurar',
                    );
                }
            }

            return new TransporteGraph(
                $config['tenant'],
                $config['cliente'],
                $config['secreto'],
                $config['remitente'],
            );
        });
    }
}
