<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
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
 */
class CifrarTextoLibreCommand extends Command
{
    protected $signature = 'privacidad:cifrar-texto-libre {--ejecutar : Reescribe de verdad las filas que siguen en claro}';

    protected $description = 'Cifra el texto libre del módulo de privacidad que quedó en claro antes de la v1.18';

    /** @var list<class-string<Model>> */
    private const MODELOS = [Solicitud::class, Bloqueo::class];

    public function handle(): int
    {
        $simulacion = ! $this->option('ejecutar');

        if ($simulacion) {
            $this->warn('Modo simulación: no se modificará ninguna fila. Usar --ejecutar para cifrar.');
        }

        $total = 0;

        foreach (self::MODELOS as $clase) {
            $modelo = new $clase;
            $tabla = $modelo->getTable();
            $columnas = CifradoCast::columnasCifradasDe($modelo);
            $enClaro = 0;

            DB::table($tabla)
                ->select(['id', ...$columnas])
                ->orderBy('id')
                ->chunkById(500, function ($filas) use ($tabla, $columnas, $simulacion, &$enClaro): void {
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

                        if (! $simulacion) {
                            DB::table($tabla)->where('id', $fila->id)->update($pendientes);
                        }
                    }
                });

            $this->line(sprintf(
                '%s: %d fila(s) con texto libre en claro%s.',
                $tabla,
                $enClaro,
                $simulacion ? '' : ' cifradas',
            ));

            $total += $enClaro;
        }

        if ($total === 0) {
            $this->info('No queda texto libre en claro.');
        }

        return self::SUCCESS;
    }
}
