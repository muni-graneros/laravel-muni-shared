<?php

namespace Muni\Shared\Persona\WriteThrough;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Red de seguridad del write-through (base compartida del ecosistema).
 *
 * Detecta registros locales que NO llegaron al maestro (nunca se sincronizaron, o
 * se editaron después de la última sincronización) y avisa a los administradores.
 * Cuando ya no queda nada pendiente, marca como leídas las alertas previas: sin
 * eso, los avisos de un incidente ya resuelto se quedan en la campana para
 * siempre y no hay forma de saber si el problema sigue abierto.
 *
 * Cada sistema aporta qué registros son sincronizables y a quién se avisa.
 */
abstract class VerificarSincronizacion extends Command
{
    /** Fragmento que identifica estas alertas en la campana. Ver `resolverAvisosPrevios()`. */
    protected const MARCA_ALERTA = 'no llegaron al maestro';

    /** Consulta de los registros que SÍ deben existir en el maestro. */
    abstract protected function sincronizables(): Builder;

    /** Tabla local (para leer `sincronizado_maestro_at`). */
    abstract protected function tabla(): string;

    /** Cómo se nombra la entidad en los mensajes: 'persona', 'ciudadano'… */
    abstract protected function sustantivo(): string;

    /** Envía el aviso a los administradores del sistema. */
    abstract protected function avisarAdmins(int $total): void;

    /** Prefijo de config del maestro (ver SincronizarAlMaestro::configPrefix). */
    protected function configPrefix(): string
    {
        return 'services.personas_api';
    }

    /** ¿Hay maestro configurado? Se sobrescribe si el sistema usa otra convención. */
    protected function maestroHabilitado(): bool
    {
        return config($this->configPrefix().'.driver') === 'http';
    }

    public function handle(): int
    {
        if (! $this->maestroHabilitado()) {
            $this->info('Maestro no configurado: nada que verificar.');

            return self::SUCCESS;
        }

        $margen = (int) ($this->option('minutos') ?: 15);
        $limite = now()->subMinutes($margen);

        $pendientes = $this->pendientes($limite);

        if ($pendientes->isEmpty()) {
            $this->info('Todo sincronizado con el maestro.');
            $this->resolverAvisosPrevios();

            return self::SUCCESS;
        }

        $this->warn($pendientes->count().' '.$this->sustantivo().'(s) NO confirmados en el maestro.');
        Log::warning('Registros sin sincronizar al maestro.', [
            'tabla' => $this->tabla(),
            'total' => $pendientes->count(),
            'ids' => $pendientes->pluck('id')->take(50)->all(),
        ]);

        $this->avisarAdmins($pendientes->count());

        return self::FAILURE;
    }

    /**
     * Registros divergentes: nunca sincronizados, o editados DESPUÉS de la última
     * sincronización. El margen da tiempo a que la cola procese el write-through
     * original antes de considerarlos un problema.
     *
     * @return Collection<int, object>
     */
    protected function pendientes(\DateTimeInterface $limite): Collection
    {
        return $this->sincronizables()
            ->where('updated_at', '<=', $limite)
            ->where(function (Builder $q): void {
                $q->whereNull('sincronizado_maestro_at')
                    ->orWhereColumn('updated_at', '>', 'sincronizado_maestro_at');
            })
            ->get(['id', 'updated_at', 'sincronizado_maestro_at']);
    }

    /**
     * Marca como leídas las alertas pendientes cuando ya no hay nada desincronizado.
     */
    protected function resolverAvisosPrevios(): void
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('notifications')) {
                return;
            }

            // Se busca por un fragmento SIN acentos del cuerpo: el JSON de la
            // notificación guarda los acentos escapados (Sincronización), así que
            // filtrar por el título falla en silencio.
            $resueltas = DB::table('notifications')
                ->whereNull('read_at')
                ->where('data', 'like', '%'.self::MARCA_ALERTA.'%')
                ->update(['read_at' => now(), 'updated_at' => now()]);

            if ($resueltas > 0) {
                $this->info($resueltas.' alerta(s) de sincronización marcadas como resueltas.');
                Log::info('Alertas de sincronización resueltas automáticamente.', ['total' => $resueltas]);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudieron resolver las alertas de sincronización.', ['error' => $e->getMessage()]);
        }
    }
}
