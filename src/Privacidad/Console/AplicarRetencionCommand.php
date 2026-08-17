<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\NingunTitularVencido;

class AplicarRetencionCommand extends Command
{
    protected $signature = 'privacidad:aplicar-retencion {--ejecutar : Aplica los cambios de verdad}';

    protected $description = 'Anonimiza y purga los datos cuyo plazo de retención venció';

    /** Nombre del candado. Compartido con el `withoutOverlapping()` del schedule. */
    public const CANDADO = 'privacidad:aplicar-retencion';

    public function handle(AplicarRetencion $retencion, Cache $cache): int
    {
        // El destructivo es opt-in: nadie descubre en producción que un cron
        // llevaba semanas borrando lo que no correspondía.
        $simulacion = ! $this->option('ejecutar');

        if ($simulacion) {
            $this->warn('Modo simulación: no se modificará ningún dato. Usar --ejecutar para aplicar.');

            return $this->correr($retencion, simulacion: true);
        }

        // Candado solo en el camino destructivo. La simulación es de solo
        // lectura y tiene que poder mirarse siempre, incluso mientras corre una
        // supresión: es la pantalla que alguien consulta para entender qué está
        // pasando.
        //
        // El candado va en el COMANDO y no solo en el `withoutOverlapping()` del
        // schedule, porque el solape que se midió no fue entre dos corridas del
        // cron: fue entre un `docker compose exec` cuyo timeout venció —el
        // proceso siguió vivo dentro del contenedor— y la corrida que alguien
        // lanzó encima. MariaDB abortó una de las dos con «SQLSTATE 1020 Record
        // has changed since last read», que en SQLite y con un solo proceso la
        // suite no puede ver.
        //
        // `store()` devuelve el Repository, que NO es LockProvider: quien sabe
        // de candados es el store de abajo. Con el Repository la comprobación
        // daba siempre false y el candado no se tomaba nunca — o sea, el defecto
        // seguía ahí con el código escrito. Lo detectó la prueba que lanza una
        // corrida con el candado ya tomado; leerlo no.
        $store = $cache->store()->getStore();
        $candado = $store instanceof LockProvider
            ? $store->lock(self::CANDADO, (int) config('privacidad.retencion.candado_segundos', 21600))
            : null;

        if ($candado !== null && ! $candado->get()) {
            $this->error(
                'No se ejecutó: ya hay una corrida de retención en curso. '
                .'Dos corridas simultáneas se pisan (MariaDB aborta una con el error 1020) '
                .'y la que aborta queda a mitad.',
            );

            return self::FAILURE;
        }

        try {
            return $this->correr($retencion, simulacion: false);
        } finally {
            $candado?->release();
        }
    }

    private function correr(AplicarRetencion $retencion, bool $simulacion): int
    {
        $resumen = $retencion->ejecutar($simulacion);

        if ($resumen->sinNadaQueRevisar()) {
            // "No hay vencidos" y "nunca se miró" se ven idénticos desde afuera,
            // y las dos causas de abajo son el estado normal de un sistema recién
            // instalado. Un cron diario informando cumplimiento de un módulo que
            // no está corriendo es peor que no tener el cron: da por cubierto lo
            // que nadie cubrió. Igual que `privacidad:rat`, se dice en voz alta.
            $this->avisarSiNoHayNadaQueRevisar();

            $this->info('No hay titulares con plazo de retención vencido.');

            return self::SUCCESS;
        }

        $this->table(['Finalidad', 'Titulares'], array_map(
            fn (array $fila): array => [$fila['finalidad'], $fila['titulares']],
            $resumen->porFinalidad,
        ));

        // Las tres líneas que faltaban en la pantalla de confirmación. La tabla
        // de arriba es POR FINALIDAD y sus conjuntos se superponen: en la corrida
        // real sumaba 50.041 sobre 20.027 personas, y quien la leyera antes de
        // autorizar el `--ejecutar` creía que había 50 mil personas por suprimir.
        // Ninguno de esos números era, además, el que se iba a tocar.
        $this->line('Los conjuntos por finalidad se superponen: la suma de la tabla NO es un total de personas.');
        $this->line("Personas distintas alcanzadas: {$resumen->personas}");
        $this->line(
            ($simulacion ? 'Se suprimirían: ' : 'Suprimidas: ')
            .($simulacion ? $resumen->suprimibles : $resumen->suprimidos)
            .' — solo quienes vencieron en TODAS las finalidades con plazo.'
        );

        $this->avisarSiNoSePropagaAlMaestro();

        return self::SUCCESS;
    }

    /**
     * Las dos razones por las que la retención puede no haber revisado nada.
     * Van en el comando y no en `AplicarRetencion` a propósito: el servicio
     * devuelve un resumen, y quien necesita el aviso es la persona (o el correo
     * del cron) que lee la salida.
     */
    private function avisarSiNoHayNadaQueRevisar(): void
    {
        $sistema = (string) config('privacidad.sistema');

        $conPlazo = Finalidad::query()
            ->delSistema($sistema)
            ->where('activa', true)
            ->whereNotNull('plazo_retencion_meses')
            ->count();

        if ($conPlazo === 0) {
            $this->warn(
                "El sistema «{$sistema}» no declaró ninguna finalidad vigente con plazo de retención: "
                .'no se revisó nada. Sembrar las finalidades con su `plazo_retencion_meses`.',
            );
        }

        if (app(ResuelveTitularesVencidos::class) instanceof NingunTitularVencido) {
            $this->warn(
                'El sistema no implementó ResuelveTitularesVencidos (sigue el enlace por defecto, '
                .'que nunca devuelve titulares): la retención NO está operativa.',
            );
        }
    }

    /**
     * Se avisa solo cuando hay titulares en juego, no siempre: un aviso que sale
     * en todas las corridas de todos los sistemas se vuelve ruido de fondo y
     * enseña a ignorar la consola del cron. Acá el aviso aparece justo en la
     * pantalla que alguien mira antes de autorizar un `--ejecutar` que, sin la
     * declaración, ni siquiera va a arrancar.
     */
    private function avisarSiNoSePropagaAlMaestro(): void
    {
        if (app()->bound(PropagaSupresion::class)) {
            return;
        }

        $this->warn(
            'Este sistema no declaró qué debe pasar en el maestro de personas al suprimir a un titular, '
            .'así que la retención se negará a ejecutar. Enlazar Contratos\PropagaSupresion con la '
            .'implementación que propaga la supresión al maestro, o con SupresionSoloLocal si este '
            .'sistema no es modelo de lectura del maestro.',
        );
    }
}
