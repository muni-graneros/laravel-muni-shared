<?php

namespace Muni\Shared;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Muni\Shared\Console\ConfigurarCorreoCommand;
use Muni\Shared\Console\MuniDocsCommand;
use Muni\Shared\Console\ProbarCorreoCommand;
use Muni\Shared\Correo\TransporteGraph;
use Muni\Shared\Privacidad\BitacoraEnBaseDeDatos;
use Muni\Shared\Privacidad\Console\AplicarRetencionCommand;
use Muni\Shared\Privacidad\Console\CifrarTextoLibreCommand;
use Muni\Shared\Privacidad\Console\DiagnosticoCommand;
use Muni\Shared\Privacidad\Console\ExportarRatCommand;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\NingunTitularVencido;
use Muni\Shared\Seguridad\CredencialesDePlantilla;

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
        $this->mergeConfigFrom(__DIR__.'/../config/credenciales-de-plantilla.php', 'credenciales-de-plantilla');

        // Enlace por defecto: un sistema que ya tenga su propia trazabilidad
        // puede sustituirlo sin tocar el módulo.
        $this->app->bind(
            RegistroDeEvidencia::class,
            BitacoraEnBaseDeDatos::class,
        );

        $this->app->bind(
            ResuelveTitularesVencidos::class,
            NingunTitularVencido::class,
        );
    }

    public function boot(): void
    {
        // Va lo primero, antes de cualquier otra cosa del arranque: si las
        // credenciales de plantilla llegaron a producción, no seguimos. Se
        // llama sola —el sistema no escribe ni una línea— por la misma razón
        // que `agendarRetencion()` más abajo se agenda sola y no desde un paso
        // documentado en el README: «es el paso que nadie escribe». La clase
        // vivía SOLO en el scaffold hasta esta versión, y ningún otro sistema
        // del ecosistema la tenía. Ver el docblock de la clase.
        CredencialesDePlantilla::comprobar();

        // Las migraciones se cargan y no se publican: así, actualizar el paquete
        // propaga el esquema a los 8 sistemas con un `migrate`, sin un paso de
        // publicación por repo que alguien va a olvidar.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/privacidad.php' => config_path('privacidad.php'),
            ], 'privacidad-config');

            $this->publishes([
                __DIR__.'/../config/credenciales-de-plantilla.php' => config_path('credenciales-de-plantilla.php'),
            ], 'credenciales-de-plantilla-config');

            $this->publishes([
                __DIR__.'/../stubs/privacidad' => base_path('docs/privacidad'),
            ], 'privacidad-stubs');
        }

        $this->registrarCorreoPorGraph();
        $this->agendarRetencion();

        if ($this->app->runningInConsole()) {
            $this->commands([
                MuniDocsCommand::class,
                ProbarCorreoCommand::class,
                ConfigurarCorreoCommand::class,
                AplicarRetencionCommand::class,
                CifrarTextoLibreCommand::class,
                DiagnosticoCommand::class,
                ExportarRatCommand::class,
            ]);
        }
    }

    /**
     * Agenda la retención SOLO si el sistema declaró la hora.
     *
     * Por qué el paquete la agenda en vez de dejarlo escrito en el README de
     * adopción: porque eso ya se probó y no funcionó. En el sistema real el
     * módulo quedó instalado, migrado, sembrado y con los contratos enlazados, y
     * `schedule:list` no listaba ninguno de sus dos comandos: la obligación
     * legal de suprimir dependía de que alguien se acordara de tipear el
     * comando. Es el paso que nadie escribe.
     *
     * Y por qué igual hace falta una clave de configuración en vez de agendarlo
     * siempre: instalar un paquete no puede poner a correr un destructivo en
     * ocho sistemas. La clave es una línea en el `.env`, decidida por el
     * municipio, y sin ella no se agenda nada.
     *
     * Los dos candados van juntos y ninguno sobra: `withoutOverlapping()` impide
     * que la corrida de mañana entre encima de la de hoy si todavía no terminó
     * —a ~17 personas por segundo, la primera corrida sobre un backlog real dura
     * más que un día—, y `onOneServer()` impide que dos servidores del mismo
     * despliegue la corran a la vez. El solape medido en MariaDB aborta una de
     * las dos corridas con el error 1020, a mitad.
     */
    private function agendarRetencion(): void
    {
        // callAfterResolving y no `app(Schedule::class)`: resolver el scheduler
        // acá adentro lo construiría en toda petición web, donde no se usa.
        //
        // Y la hora se lee DENTRO del callback, no acá afuera: leerla en el boot
        // del provider la congela en el instante más temprano del ciclo de vida,
        // antes de que un adoptante pueda ajustar la configuración (o una prueba
        // ejercitarla). El scheduler se resuelve cuando corre `schedule:run` o
        // `schedule:list`, que es cuando la configuración ya está completa.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $hora = trim((string) config('privacidad.retencion.hora', ''));

            if ($hora === '') {
                return;
            }

            $schedule->command(AplicarRetencionCommand::class, ['--ejecutar'])
                ->dailyAt(trim($hora))
                ->withoutOverlapping()
                ->onOneServer();
        });
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
