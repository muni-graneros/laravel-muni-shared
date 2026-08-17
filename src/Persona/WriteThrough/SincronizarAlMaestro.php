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
use Muni\Shared\Privacidad\SupresionEnCurso;

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

    /**
     * ¿Este job nació mientras se estaba suprimiendo a un titular?
     *
     * Se resuelve en el CONSTRUCTOR y viaja con el job, no se consulta en
     * `handle()`: el job se construye dentro del observador `saved` —o sea,
     * dentro de la supresión— y se ejecuta después en un worker, donde la marca
     * de proceso ya no existe.
     *
     * Tiene default `false` a propósito: los jobs que ya estaban en la cola
     * cuando se actualizó el paquete no traen la propiedad en su payload, y sin
     * default PHP dejaría la propiedad tipada sin inicializar y el worker
     * moriría al leerla.
     */
    public bool $duranteSupresion = false;

    public function __construct(
        public int|string $registroId,
        public ?string $actorEmail = null,
        public ?string $actorNombre = null,
    ) {
        $this->duranteSupresion = SupresionEnCurso::activa();
    }

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

    /**
     * ¿Este payload corresponde a un registro ya suprimido?
     *
     * El centinela `ANON-` es la convención del ecosistema para el documento de
     * una persona anonimizada (`nro_documento` es NOT NULL y UNIQUE, así que no
     * se puede anular ni repetir un valor fijo). Se mira el payload y no el
     * modelo porque el payload es lo único que este paquete conoce de forma
     * uniforme: el mapeo de columnas lo define cada sistema.
     *
     * Alcance, sin adornos: esto reconoce el centinela `ANON-` en la clave
     * `nro_documento`, que es la del contrato del maestro. Un sistema que
     * anonimice con otro centinela, o que mande el documento bajo otra clave,
     * tiene que sobrescribir este método o vuelve a empujar registros
     * suprimidos.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function suprimido(array $payload): bool
    {
        $documento = $payload['nro_documento'] ?? null;

        return is_string($documento) && str_starts_with($documento, 'ANON-');
    }

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

        // Dos puertas al mismo defecto, y las dos hay que cerrarlas acá porque
        // el paquete no controla el observador de cada sistema.
        //
        // Contexto medido contra el maestro de desarrollo: anonimizar hace
        // `save()`, el `Persona::saved` del adoptante despacha este job, y el
        // maestro —que hace upsert por RUT— recibía primero la identidad REAL
        // (el `save()` de purgar, con la persona todavía íntegra) y después un
        // `ANON-{id}` que crea una persona nueva. 120 filas en 60
        // anonimizaciones: la retención daba de alta en el registro central
        // justo a quien acababa de suprimir.
        if ($this->duranteSupresion) {
            // Warning y no silencio: llegar acá significa que el observador del
            // sistema despachó el job igual, o sea que falta el paso de adopción.
            // Sin PII, que el id basta para trazar.
            Log::warning('Write-through descartado: el registro se escribió durante una supresión.', [
                'sistema' => $this->sistema(),
                'registro_id' => $registro->id,
                'accion' => 'guardar el observador `saved` con SupresionEnCurso::activa()',
            ]);

            return;
        }

        $payload = $this->payload($registro);

        // La segunda puerta, que la marca de proceso NO cierra: el cron
        // `personas:resincronizar` compara `updated_at > sincronizado_maestro_at`
        // y la anonimización mueve `updated_at`. Quince minutos después de una
        // supresión perfecta, el reparador re-despacha a la persona ya
        // anonimizada y crea el `ANON-{id}` en el maestro igual. Acá no hay
        // supresión en curso ni la hubo: hay un registro que ya está suprimido y
        // que no debe volver a empujarse nunca más.
        if ($this->suprimido($payload)) {
            Log::warning('Write-through descartado: el registro ya está suprimido.', [
                'sistema' => $this->sistema(),
                'registro_id' => $registro->id,
            ]);

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
                $payload,
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
