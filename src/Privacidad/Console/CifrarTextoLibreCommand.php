<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\CifradoCast;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * Cifra el texto libre que quedó guardado en claro antes de que el módulo
 * cifrara en reposo.
 *
 * Es una migración de DATOS y va como comando, no como migración de esquema:
 * corre lo que haga falta, se puede repetir y se puede mirar antes de aplicar.
 * `migrate` en un despliegue no es el lugar para reescribir miles de filas con
 * la APP_KEY del sistema.
 *
 * Idempotente por construcción: una fila ya cifrada se reconoce por la forma
 * del payload (ver CifradoCast::estaCifrado) y no se toca, así que correrlo dos
 * veces deja el mismo ciphertext byte a byte. Qué columnas cifra lo dicen los
 * modelos, no una lista acá: si mañana un modelo suma una columna cifrada, el
 * comando la ve.
 *
 * Corre con el sistema en línea: entre la lectura de un lote y el UPDATE de
 * cada fila, el sistema puede guardar un valor nuevo. Por eso el UPDATE va
 * condicionado a lo que se leyó, y la fila que cambió entretanto se salta y se
 * informa (sin su contenido) en vez de pisarla con el texto viejo cifrado.
 */
class CifrarTextoLibreCommand extends Command
{
    protected $signature = 'privacidad:cifrar-texto-libre {--ejecutar : Reescribe de verdad las filas que siguen en claro}';

    protected $description = 'Cifra el texto libre del módulo de privacidad que quedó en claro antes de la v1.18';

    /** Nombre del candado que impide dos corridas `--ejecutar` a la vez. */
    public const CANDADO = 'privacidad:cifrar-texto-libre';

    /** Vencimiento del candado: libera solo si el proceso muere sin soltarlo. */
    private const CANDADO_SEGUNDOS = 3600;

    /** @var list<class-string<Model>> */
    private const MODELOS = [Solicitud::class, Bloqueo::class];

    public function handle(Cache $cache): int
    {
        $simulacion = ! $this->option('ejecutar');

        if ($simulacion) {
            $this->warn('Modo simulación: no se modificará ninguna fila. Usar --ejecutar para cifrar.');

            return $this->cifrar(simulacion: true);
        }

        // Candado solo en el camino que escribe, igual que en
        // privacidad:aplicar-retencion. `store()` devuelve el Repository, que
        // NO es LockProvider: quien sabe de candados es el store de abajo.
        $store = $cache->store()->getStore();
        $candado = $store instanceof LockProvider
            ? $store->lock(self::CANDADO, self::CANDADO_SEGUNDOS)
            : null;

        if ($candado !== null && ! $candado->get()) {
            $this->error(
                'No se ejecutó: ya hay otra corrida de privacidad:cifrar-texto-libre --ejecutar en curso. '
                .'Esperar a que termine y volver a correrlo.',
            );

            return self::FAILURE;
        }

        try {
            return $this->cifrar(simulacion: false);
        } finally {
            $candado?->release();
        }
    }

    private function cifrar(bool $simulacion): int
    {
        $total = 0;

        foreach (self::MODELOS as $clase) {
            $modelo = new $clase;
            $tabla = $modelo->getTable();
            $columnas = CifradoCast::columnasCifradasDe($modelo);
            $enClaro = 0;
            $cifradas = 0;
            $saltadas = 0;

            DB::table($tabla)
                ->select(['id', ...$columnas])
                ->orderBy('id')
                ->chunkById(500, function ($filas) use ($tabla, $columnas, $simulacion, &$enClaro, &$cifradas, &$saltadas): void {
                    foreach ($filas as $fila) {
                        $pendientes = [];

                        foreach ($columnas as $columna) {
                            $valor = $fila->{$columna};

                            if ($valor === null || CifradoCast::estaCifrado((string) $valor)) {
                                continue;
                            }

                            // Tal cual está: un JSON viejo se cifra como el
                            // JSON que ya es, que es lo mismo que escribe el
                            // cast `:array`.
                            $pendientes[$columna] = CifradoCast::cifrar((string) $valor);
                        }

                        if ($pendientes === []) {
                            continue;
                        }

                        $enClaro++;

                        if ($simulacion) {
                            continue;
                        }

                        // Solo si cada columna que se reescribe sigue teniendo
                        // el valor que se leyó. Si el sistema guardó algo
                        // entretanto, la fila no coincide, no se toca y se
                        // cuenta: la próxima corrida la vuelve a evaluar. Las
                        // columnas pendientes nunca son null (se saltan
                        // arriba), así que basta la igualdad.
                        $consulta = DB::table($tabla)->where('id', $fila->id);

                        foreach (array_keys($pendientes) as $columna) {
                            $consulta->where($columna, $fila->{$columna});
                        }

                        if ($consulta->update($pendientes) > 0) {
                            $cifradas++;
                        } else {
                            $saltadas++;
                        }
                    }
                });

            $this->line(sprintf(
                '%s: %d fila(s) con texto libre en claro%s.',
                $tabla,
                $enClaro,
                $simulacion ? '' : sprintf(', %d cifrada(s)', $cifradas),
            ));

            if ($saltadas > 0) {
                // Sin ids ni contenido: el texto es dato personal y la
                // cantidad basta para saber que hay que volver a correrlo.
                $this->warn(sprintf(
                    '%s: %d fila(s) cambiaron durante la corrida y no se tocaron, para no pisar lo que guardó el sistema. '
                    .'Volver a correr el comando para cifrarlas.',
                    $tabla,
                    $saltadas,
                ));
            }

            $total += $enClaro;
        }

        if ($total === 0) {
            $this->info('No queda texto libre en claro.');
        }

        return self::SUCCESS;
    }
}
