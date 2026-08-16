<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Muni\Shared\Privacidad\Modelos\DecisionAutomatizada;
use Muni\Shared\Privacidad\Modelos\Encargado;
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
        //
        // `with('encargados')` para no convertir el mapeo de abajo en un N+1:
        // sin esto, cada finalidad dispara su propia consulta a la tabla pivote.
        $finalidades = Finalidad::query()->delSistema($sistema)->with('encargados')->orderBy('codigo')->get();

        // Se lee una sola vez y se reutiliza en los dos caminos (json y tabla):
        // es la misma pregunta —¿qué decisiones automatizadas toma este
        // sistema?— contestada en dos formatos, no dos preguntas distintas.
        // `with('finalidad')` por el mismo motivo que `with('encargados')`
        // arriba: sin él, el `finalidad_id`.codigo de cada decisión dispara su
        // propia consulta al mapear más abajo.
        $decisiones = DecisionAutomatizada::query()->delSistema($sistema)->with('finalidad')->get();

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
                    'excepcion_dato_sensible' => $f->excepcion_dato_sensible?->value,
                    'es_accesoria' => $f->es_accesoria,
                    'activa' => $f->activa,
                    'plazo_retencion_meses' => $f->plazo_retencion_meses,
                    'categorias_datos' => $f->categorias_datos,
                    'destinatarios' => $f->destinatarios,
                    'encargados' => $f->encargados->map(fn (Encargado $e): array => [
                        'nombre' => $e->nombre,
                        'rol' => $e->rol,
                        'contrato_vence_en' => $e->contrato_vence_en?->toDateString(),
                    ])->all(),
                ])->all(),
                // Siempre presente, aunque esté vacía: un arreglo vacío dice
                // «se revisó y no hay ninguna»; una clave ausente diría «este
                // RAT no llegó a contestar la pregunta». Son respuestas
                // distintas ante una fiscalización y el export no las mezcla.
                'decisiones_automatizadas' => $decisiones->map(fn (DecisionAutomatizada $d): array => [
                    'descripcion' => $d->descripcion,
                    'logica' => $d->logica,
                    'consecuencias' => $d->consecuencias,
                    'permite_revision_humana' => $d->permite_revision_humana,
                    'finalidad' => $d->finalidad?->codigo,
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
            ['Código', 'Finalidad', 'Base de licitud', 'Norma', 'Causal dato sensible', 'Retención (meses)', 'Estado'],
            $finalidades->map(fn (Finalidad $f): array => [
                $f->codigo,
                $f->nombre,
                $f->base_licitud->etiqueta(),
                $f->norma_habilitante ?? '—',
                $f->excepcion_dato_sensible?->etiqueta() ?? '—',
                $f->plazo_retencion_meses ?? 'sin plazo',
                $f->activa ? 'Vigente' : 'Dada de baja',
            ])->all(),
        );

        // Después de la tabla y no antes: es un aviso sobre el estado de los
        // contratos, no sobre las finalidades, y mezclarlo adentro de la tabla
        // (que es por finalidad) le haría perder la fila que le corresponde a
        // cada encargado —una finalidad puede tener varios, y un encargado
        // varias finalidades—.
        $sinContrato = Encargado::query()->where('sistema', $sistema)->sinContratoVigente()->pluck('nombre');

        if ($sinContrato->isNotEmpty()) {
            $this->warn('Encargados sin contrato al día: '.$sinContrato->implode(', ')
                .'. La ley exige contrato con cada encargado del tratamiento.');
        }

        // Se declara el caso vacío en vez de callar: un RAT mudo sobre el
        // tema es indistinguible de uno que no llegó a revisarlo, y la ley da
        // derecho a no ser objeto de decisiones automatizadas con efectos
        // significativos — contestar «ninguna» es contestar la pregunta.
        if ($decisiones->isEmpty()) {
            $this->info("El sistema «{$sistema}» no declara decisiones automatizadas con efectos significativos sobre los titulares.");

            return self::SUCCESS;
        }

        $sinRevision = $decisiones->where('permite_revision_humana', false)->pluck('descripcion');

        if ($sinRevision->isNotEmpty()) {
            $this->warn('Decisiones automatizadas sin revisión humana: '.$sinRevision->implode(', ')
                .'. El titular tiene derecho a pedir intervención humana.');
        }

        return self::SUCCESS;
    }
}
