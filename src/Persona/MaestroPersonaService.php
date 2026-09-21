<?php

namespace Muni\Shared\Persona;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente del MAESTRO DE PERSONAS (personas-api) para AUTOCOMPLETAR formularios por
 * RUT en cualquier sistema del ecosistema. Devuelve los datos de la persona más la
 * "presencia" (otros sistemas donde ya aparece), para no re-tipear a quien ya existe.
 *
 * Contrato de retorno de buscar():
 *   - null                                  → el maestro NO respondió (caída/timeout).
 *                                             El llamador DEBE avisar (degradación visible).
 *   - ['data' => [], 'presencia' => []]      → el maestro respondió pero el RUT no existe
 *                                             (persona nueva): registro normal, sin ruido.
 *   - ['data' => [...], 'presencia' => [...]]→ persona encontrada: autocompletar.
 *
 * Config esperada (services.personas_api.*): url, token, sistema, timeout. El nombre
 * del sistema propio se excluye de la presencia.
 */
class MaestroPersonaService
{
    public function __construct(
        /** Slug del sistema que consulta (se envía como X-Sistema y se excluye de la presencia). */
        private readonly string $sistemaPropio,
    ) {}

    /**
     * @return array{data: array<string, mixed>, presencia: array<int, string>}|null
     */
    public function buscar(string $rut, ?string $actorEmail = null, ?string $actorNombre = null): ?array
    {
        $limpio = preg_replace('/[^0-9kK]/', '', $rut);
        if (! $limpio) {
            return null;
        }

        $base = (string) config('services.personas_api.url');
        $token = (string) config('services.personas_api.token');
        if ($base === '' || $token === '') {
            return null;
        }

        try {
            $resp = Http::withToken($token)
                ->withHeaders(array_filter([
                    'X-Sistema' => (string) config('services.personas_api.sistema', $this->sistemaPropio),
                    'X-Actor-Email' => $actorEmail,
                    'X-Actor-Nombre' => $actorNombre,
                ]))
                ->acceptJson()
                ->timeout((int) config('services.personas_api.timeout', 5))
                ->get(rtrim($base, '/').'/api/servicios/v1/personas/'.urlencode($rut));

            if ($resp->status() === 404) {
                return ['data' => [], 'presencia' => []];
            }
            if (! $resp->successful()) {
                return null;
            }

            // La presencia puede venir como lista de strings o de objetos {sistema: ...}.
            $presencia = collect((array) $resp->json('presencia'))
                ->map(fn ($s): string => is_array($s) ? (string) ($s['sistema'] ?? '') : (string) $s)
                ->filter()
                ->reject(fn (string $s): bool => $s === $this->sistemaPropio)
                ->values()->all();

            return ['data' => (array) $resp->json('data'), 'presencia' => $presencia];
        } catch (\Throwable $e) {
            // Sin `$e->getMessage()`: Guzzle lo arma con la URI completa, y en
            // el path va el RUT. Lo que sirve para operar es la clase del fallo,
            // el status y el número de error de cURL; eso es lo que se escribe.
            $fallo = MaestroNoDisponible::porExcepcion($e);

            Log::info('Maestro de personas no disponible.', [
                'sistema' => $this->sistemaPropio,
                'error' => $fallo->causa,
                'status' => $fallo->status,
                'curl_errno' => $fallo->curlErrno,
            ]);

            return null;
        }
    }
}
