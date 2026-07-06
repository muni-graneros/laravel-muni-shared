<?php

namespace Muni\Shared\Persona;

use Illuminate\Support\Facades\Http;

/**
 * Resolvedor contra la API del MAESTRO DE PERSONAS (contenedor personas-api).
 *
 * Los módulos consumen personas por HTTP en vez de la tabla local. El servicio
 * audita cada consulta (persona_lookups) con el sistema consumidor (X-Sistema);
 * la auditoría local del controlador (con el user_id de quien busca) se mantiene
 * — juntas forman la trazabilidad completa.
 */
class ApiPersonaResolver implements PersonaResolverInterface
{
    public function findByRut(string $rut): ?PersonaDTO
    {
        $resp = Http::withToken((string) config('services.personas_api.token'))
            ->withHeaders(['X-Sistema' => (string) config('services.personas_api.sistema', 'discapacidad')])
            ->acceptJson()
            ->timeout((int) config('services.personas_api.timeout', 5))
            ->get(rtrim((string) config('services.personas_api.url'), '/').'/api/servicios/v1/personas/'.urlencode($rut));

        if ($resp->status() === 404) {
            return null;
        }

        $data = $resp->throw()->json('data');

        return PersonaDTO::fromArray($data + ['source' => 'api', 'system' => 'maestro']);
    }

    public function existsByRut(string $rut): bool
    {
        return $this->findByRut($rut) !== null;
    }

    public function getSource(): string
    {
        return 'api';
    }
}
