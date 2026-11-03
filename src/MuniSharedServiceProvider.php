<?php

namespace Muni\Shared;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Muni\Shared\Console\ConfigurarCorreoCommand;
use Muni\Shared\Console\LimpiarDatosOperativosCommand;
use Muni\Shared\Console\MuniDocsCommand;
use Muni\Shared\Console\ProbarCorreoCommand;
use Muni\Shared\Correo\IncrustarLogoInstitucional;
use Muni\Shared\Correo\PlantillaInstitucional;
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
        $this->mergeConfigFrom(__DIR__.'/../config/datos-operativos.php', 'datos-operativos');
        $this->mergeConfigFrom(__DIR__.'/../config/idempotencia.php', 'idempotencia');
        $this->mergeConfigFrom(__DIR__.'/../config/correo-institucional.php', 'correo-institucional');

        $this->plantillaInstitucional();

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
            $this->publicarConfiguraciones();
        }

        $this->registrarCorreoPorGraph();
        $this->agendarRetencion();
        $this->plantillaDeNotificaciones();

        // El logo viaja incrustado en el correo (cid:), no por URL: lo
        // adjunta el listener justo antes de enviar, y solo si la plantilla
        // lo referencia.
        Event::listen(MessageSending::class, IncrustarLogoInstitucional::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                MuniDocsCommand::class,
                ProbarCorreoCommand::class,
                ConfigurarCorreoCommand::class,
                LimpiarDatosOperativosCommand::class,
                AplicarRetencionCommand::class,
                CifrarTextoLibreCommand::class,
                DiagnosticoCommand::class,
                ExportarRatCommand::class,
            ]);
        }
    }

    /**
     * Lo que el sistema puede publicar en su `config/` y en su `docs/`.
     *
     * Las migraciones NO se publican: se cargan. Publicar los archivos de
     * configuración es opcional —el paquete los fusiona solo— y está para el
     * sistema que los quiera versionar y editar a mano.
     */
    private function publicarConfiguraciones(): void
    {
        $this->publishes([
            __DIR__.'/../config/privacidad.php' => config_path('privacidad.php'),
        ], 'privacidad-config');

        $this->publishes([
            __DIR__.'/../config/credenciales-de-plantilla.php' => config_path('credenciales-de-plantilla.php'),
        ], 'credenciales-de-plantilla-config');

        $this->publishes([
            __DIR__.'/../config/datos-operativos.php' => config_path('datos-operativos.php'),
        ], 'datos-operativos-config');

        $this->publishes([
            __DIR__.'/../config/idempotencia.php' => config_path('idempotencia.php'),
        ], 'idempotencia-config');

        $this->publishes([
            __DIR__.'/../stubs/privacidad' => base_path('docs/privacidad'),
        ], 'privacidad-stubs');
    }

    /**
     * La plantilla institucional de correo la pone el paquete, no cada sistema.
     *
     * Se aplica en la RESOLUCIÓN del renderer y no con `config()` en el
     * `register()`, por dos razones.
     *
     * La primera: la app puede fijar su `mail.markdown.theme` y su
     * `mail.markdown.paths` después de que los providers se registran, y gana el
     * que se registró después. Leyendo la configuración en el momento de
     * resolver, gana la plantilla del paquete, que es lo que se quiere: el logo,
     * el color y el pie son de la Municipalidad, no del sistema que manda el
     * correo.
     *
     * La segunda: el renderer es un singleton. Configurarlo al resolverlo lo
     * deja bien una vez y bien las veces que se vuelva a resolver (por ejemplo,
     * con `config:cache`), sin duplicar rutas.
     */
    private function plantillaInstitucional(): void
    {
        $this->app->afterResolving(Markdown::class, function (Markdown $markdown): void {
            $rutas = array_values(array_unique(array_merge(
                [PlantillaInstitucional::rutaComponentes()],
                (array) config('mail.markdown.paths', []),
            )));

            // Las vistas del paquete van PRIMERO: `Markdown` usa la primera
            // ruta que tenga el componente, así que así la plantilla
            // institucional le gana a la `resources/views/vendor/mail` de cada
            // app y a las del framework.
            $markdown->loadComponentsFrom($rutas);
            $markdown->theme(PlantillaInstitucional::TEMA);

            // Y el config queda igual, porque hay caminos del framework que lo
            // leen al enviar y no al resolver (el canal de correo de las
            // notificaciones, por ejemplo).
            config([
                'mail.markdown.theme' => PlantillaInstitucional::TEMA,
                'mail.markdown.paths' => $rutas,
            ]);
        });
    }

    /**
     * La plantilla de notificaciones del paquete va DETRÁS de la de la app.
     *
     * `notifications::email` es el CONTENIDO del correo de una notificación:
     * el saludo, las líneas, el botón y la despedida. La marca institucional
     * (logo, franja, color y pie) ya la pone `mail::message`, que sí es del
     * paquete. El contenido es de cada sistema: su idioma, sus palabras y sus
     * datos.
     *
     * Por eso la plantilla del paquete va en el medio, no adelante: la de la
     * app gana, y la del framework —que está en inglés— va última.
     *
     *   1. `resources/views/vendor/notifications` de la app, si existe,
     *   2. `resources/views/notifications` del paquete,
     *   3. la del framework.
     *
     * El primer paso es el que importa: atencionvecino sobrescribe esa
     * plantilla para no mostrar la URL del requerimiento en el aviso de
     * respaldo. Ese control de privacidad no puede quedar en manos del
     * paquete, y por eso la plantilla del paquete NO se registra como una
     * vista más de la app.
     *
     * Se aplica al resolver la vista, y no al registrar el provider, porque
     * el framework registra la suya en su `boot()`: hay que correr después que
     * él, no antes. Por lo mismo el callback va en `boot()` de este provider
     * (los providers del framework se registran antes que los de los
     * paquetes) y no en `register()`.
     */
    private function plantillaDeNotificaciones(): void
    {
        $this->callAfterResolving('view', function (ViewFactory $view): void {
            $finder = $view->getFinder();

            // El finder del framework (`Illuminate\View\FileViewFinder`) es el
            // que sabe qué rutas hay para cada namespace. Se comprueba antes
            // de usarlo: si una app lo cambiara por otro, el paquete no
            // inventa un orden de búsqueda que no puede verificar, y la app
            // sigue con su plantilla y la del framework.
            if (! method_exists($finder, 'getHints')) {
                return;
            }

            $hints = $finder->getHints()['notifications'] ?? [];

            $rutas = $this->rutasDeNotificacionesDeLaApp();
            $rutas[] = dirname(__DIR__).'/resources/views/notifications';

            // Lo que ya estaba (la del framework, y la de la app si el
            // framework la encontró) va después, sin repetir rutas.
            foreach ($hints as $hint) {
                if (! in_array($hint, $rutas, true)) {
                    $rutas[] = $hint;
                }
            }

            $finder->replaceNamespace('notifications', $rutas);
        });
    }

    /**
     * Las carpetas donde la app puede tener su propia plantilla de
     * notificaciones.
     *
     * La del framework las busca en cada ruta de `config('view.paths')`, y
     * por lo tanto en la de la app (`resources/views/vendor/notifications`).
     * Se mira también esa ruta explícita para que el orden no dependa de que
     * la app tenga `view.paths` configurado a mano.
     *
     * @return array<int, string>
     */
    private function rutasDeNotificacionesDeLaApp(): array
    {
        $rutas = [];

        $candidatas = [resource_path('views/vendor/notifications')];

        foreach ((array) config('view.paths', []) as $ruta) {
            $candidatas[] = rtrim($ruta, '/').'/vendor/notifications';
        }

        foreach ($candidatas as $candidata) {
            if (! is_dir($candidata)) {
                continue;
            }

            $real = realpath($candidata) ?: $candidata;

            if (! in_array($real, $rutas, true)) {
                $rutas[] = $real;
            }
        }

        return $rutas;
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
