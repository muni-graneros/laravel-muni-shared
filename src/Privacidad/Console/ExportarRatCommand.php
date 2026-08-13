<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * El registro de actividades de tratamiento es lo primero que pide una
 * fiscalización. Se genera desde la base y no desde un documento, para que no
 * pueda quedar desactualizado sin que nadie se entere.
 */
class ExportarRatCommand extends Command
{
    protected $signature = 'privacidad:rat {--json : Emite el RAT en JSON}';

    protected $description = 'Exporta el registro de actividades de tratamiento del sistema';

    public function handle(): int
    {
        $sistema = (string) config('privacidad.sistema');

        $finalidades = Finalidad::query()->delSistema($sistema)->orderBy('codigo')->get();

        if ($finalidades->isEmpty()) {
            $this->warn("El sistema «{$sistema}» no declaró ninguna finalidad de tratamiento.");

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'sistema' => $sistema,
                'generado_en' => now()->toIso8601String(),
                'responsable' => config('privacidad.responsable'),
                'finalidades' => $finalidades->map(fn (Finalidad $f): array => [
                    'codigo' => $f->codigo,
                    'nombre' => $f->nombre,
                    'base_licitud' => $f->base_licitud->value,
                    'norma_habilitante' => $f->norma_habilitante,
                    'es_accesoria' => $f->es_accesoria,
                    'plazo_retencion_meses' => $f->plazo_retencion_meses,
                    'categorias_datos' => $f->categorias_datos,
                    'destinatarios' => $f->destinatarios,
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("RAT del sistema «{$sistema}» — ".config('privacidad.responsable.nombre'));

        // Sin TTY (CI, cron, salida redirigida a un archivo) Symfony asume 80
        // columnas y envuelve las celdas: la norma habilitante termina cortada
        // a la mitad. El RAT se lee tanto en pantalla como en un log, así que
        // se fuerza un ancho amplio en vez de dejarlo a la suerte del entorno.
        $anchoPrevio = getenv('COLUMNS');
        putenv('COLUMNS=200');

        $this->table(
            ['Código', 'Finalidad', 'Base de licitud', 'Norma', 'Retención (meses)'],
            $finalidades->map(fn (Finalidad $f): array => [
                $f->codigo,
                $f->nombre,
                $f->base_licitud->etiqueta(),
                $f->norma_habilitante ?? '—',
                $f->plazo_retencion_meses ?? 'sin plazo',
            ])->all(),
        );

        putenv($anchoPrevio === false ? 'COLUMNS' : "COLUMNS={$anchoPrevio}");

        return self::SUCCESS;
    }
}
