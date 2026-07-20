<?php

namespace Muni\Shared\Persona\WriteThrough;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WRITE-THROUGH al maestro de personas (base compartida del ecosistema).
 *
 * Cuando un sistema crea o actualiza una persona en su tabla local (modelo de
 * lectura), la empuja al maestro para que siga siendo la fuente única. Va en cola
 * con reintentos: si el maestro está caído, la sincronización se difiere sin
 * frenar la atención en el mesón.
 *
 * Cada sistema extiende esta clase y aporta lo único que le es propio: de qué
 * registro se trata, cómo se mapea al payload del maestro y en qué tabla se sella
 * la confirmación. El transporte, los reintentos y el sellado viven aquí.
 */
abstract class SincronizarAlMaestro implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Si el maestro cuelga, el HTTP tiene su propio timeout; este tope evita
     *  además que un worker quede bloqueado más de lo razonable. */
    public int $timeout = 20;

    public function __construct(
        public int|string $registroId,
        public ?string $actorEmail = null,
        public ?string $actorNombre = null,
    ) {}

    /**
     * Backoff en segundos entre reintentos.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    /** El registro local a empujar. Devolver null si ya no existe (se descarta). */
    abstract protected function registro(): ?Model;

    /**
     * Mapeo del registro local al payload del maestro.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(object $registro): array;

    /** Tabla local donde se sella `sincronizado_maestro_at`. */
    abstract protected function tabla(): string;

    /** Identificador del sistema para la cabecera `X-Sistema` (auditoría del maestro). */
    abstract protected function sistema(): string;

    /** Gancho opcional para reaccionar a la respuesta del maestro. */
    protected function despuesDeSincronizar(Model $registro, mixed $respuesta): void {}

    /**
     * Prefijo de config del maestro. Por defecto el del ecosistema
     * (`services.personas_api`); licencias, por ejemplo, usa el suyo.
     */
    protected function configPrefix(): string
    {
        return 'services.personas_api';
    }

    /**
     * ¿Hay maestro configurado? Se sobrescribe cuando el sistema usa otra
     * convención (un flag `enabled` en vez de `driver`, por ejemplo).
     */
    protected function maestroHabilitado(): bool
    {
        return config($this->configPrefix().'.driver') === 'http';
    }

    public function handle(): void
    {
        if (! $this->maestroHabilitado()) {
            return; // sin maestro configurado (tests / instalación aislada)
        }

        $registro = $this->registro();
        if (! $registro) {
            return;
        }

        $resp = Http::withToken((string) config($this->configPrefix().'.token'))
            ->withHeaders(array_filter([
                'X-Sistema' => (string) config($this->configPrefix().'.sistema', $this->sistema()),
                // Atribución del funcionario que originó el cambio (auditoría del maestro).
                'X-Actor-Email' => $this->actorEmail,
                'X-Actor-Nombre' => $this->actorNombre,
            ]))
            ->acceptJson()
            ->timeout((int) config($this->configPrefix().'.timeout', 5))
            ->post(
                rtrim((string) config($this->configPrefix().'.url'), '/').'/api/servicios/v1/personas',
                $this->payload($registro),
            );

        if (! $resp->successful()) {
            // Sin datos personales en el log (Ley 19.628/21.719): el id basta para trazar.
            Log::warning('Sincronización al maestro rechazada.', [
                'sistema' => $this->sistema(),
                'registro_id' => $registro->id,
                'status' => $resp->status(),
            ]);
            $resp->throw(); // dispara reintento
        }

        // Confirmación de sincronización (red de seguridad). Se actualiza por DB
        // directo para NO tocar `updated_at`: así la comparación
        // `updated_at > sincronizado_maestro_at` solo marca cambios locales POSTERIORES.
        DB::table($this->tabla())
            ->where('id', $registro->id)
            ->update(['sincronizado_maestro_at' => now()]);

        $this->despuesDeSincronizar($registro, $resp);
    }
}
