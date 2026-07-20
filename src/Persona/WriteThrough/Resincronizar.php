<?php

namespace Muni\Shared\Persona\WriteThrough;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Auto-remediación del write-through (base compartida del ecosistema).
 *
 * Re-despacha al maestro los registros que quedaron sin sincronizar (cola caída,
 * maestro fuera de servicio, job perdido). Usa el MISMO predicado que el
 * verificador, para que lo que dispara la alerta sea exactamente lo que se
 * intenta reparar — si difieren, la alerta se queda encendida para siempre.
 */
abstract class Resincronizar extends Command
{
    /** Consulta de los registros que SÍ deben existir en el maestro. */
    abstract protected function sincronizables(): Builder;

    /** Cómo se nombra la entidad en los mensajes: 'persona', 'ciudadano'… */
    abstract protected function sustantivo(): string;

    /** Despacha el job de write-through para un registro. */
    abstract protected function despachar(Model $registro): void;

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
            $this->info('Maestro no configurado: nada que resincronizar.');

            return self::SUCCESS;
        }

        $margen = (int) ($this->option('minutos') ?: 15);
        $limite = now()->subMinutes($margen);

        $pendientes = $this->sincronizables()
            ->where('updated_at', '<=', $limite)
            ->where(function (Builder $q): void {
                $q->whereNull('sincronizado_maestro_at')
                    ->orWhereColumn('updated_at', '>', 'sincronizado_maestro_at');
            })
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('Nada pendiente de resincronizar.');

            return self::SUCCESS;
        }

        foreach ($pendientes as $registro) {
            $this->despachar($registro);
        }

        $this->info($pendientes->count().' '.$this->sustantivo().'(s) re-despachados al maestro.');
        Log::info('Resincronización re-despachada.', ['total' => $pendientes->count()]);

        return self::SUCCESS;
    }
}
