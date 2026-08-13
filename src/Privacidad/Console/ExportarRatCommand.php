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

        // No se filtra por `activa`: una finalidad dada de baja sigue siendo
        // parte del historial de tratamiento, y el RAT existe para que una
        // fiscalización vea lo que el sistema realmente hizo, no una versión
        // recortada. El estado se muestra, nunca se oculta.
        $finalidades = Finalidad::query()->delSistema($sistema)->orderBy('codigo')->get();

        // El chequeo de --json va primero: quien redirige la salida a
        // `json_decode` no puede recibir una línea de advertencia en texto
        // plano solo porque el sistema no declaró finalidades todavía.
        if ($this->option('json')) {
            $this->line(json_encode([
                'sistema' => $sistema,
                'generado_en' => now()->toIso8601String(),
                'responsable' => config('privacidad.responsable'),
                'finalidades' => $finalidades->map(fn (Finalidad $f): array => [
                    'codigo' => $f->codigo,
                    'nombre' => $f->nombre,
                    'descripcion' => $f->descripcion,
                    'base_licitud' => $f->base_licitud->value,
                    'norma_habilitante' => $f->norma_habilitante,
                    'es_accesoria' => $f->es_accesoria,
                    'activa' => $f->activa,
                    'plazo_retencion_meses' => $f->plazo_retencion_meses,
                    'categorias_datos' => $f->categorias_datos,
                    'destinatarios' => $f->destinatarios,
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($finalidades->isEmpty()) {
            $this->warn("El sistema «{$sistema}» no declaró ninguna finalidad de tratamiento.");

            return self::SUCCESS;
        }

        $this->info("RAT del sistema «{$sistema}» — ".config('privacidad.responsable.nombre'));

        $this->table(
            ['Código', 'Finalidad', 'Base de licitud', 'Norma', 'Retención (meses)', 'Estado'],
            $finalidades->map(fn (Finalidad $f): array => [
                $f->codigo,
                $f->nombre,
                $f->base_licitud->etiqueta(),
                $f->norma_habilitante ?? '—',
                $f->plazo_retencion_meses ?? 'sin plazo',
                $f->activa ? 'Vigente' : 'Dada de baja',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
