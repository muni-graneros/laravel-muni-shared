<?php

namespace Muni\Shared\Persona;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Resolvedor contra la API del MAESTRO DE PERSONAS (contenedor personas-api).
 *
 * Los módulos consumen personas por HTTP en vez de la tabla local. El servicio
 * audita cada consulta (persona_lookups) con el sistema consumidor (X-Sistema);
 * la auditoría local del controlador (con el user_id de quien busca) se mantiene
 * — juntas forman la trazabilidad completa, **siempre que cada adoptante declare
 * `services.personas_api.sistema`**. Ver el porqué en `findByRut()`.
 */
class ApiPersonaResolver implements PersonaResolverInterface
{
    /**
     * @throws MaestroNoDisponible si el maestro no respondió o respondió con
     *                             error. Nunca deja escapar la excepción del
     *                             cliente HTTP: lleva el RUT en la URI (ver el
     *                             docblock de `MaestroNoDisponible`).
     */
    public function findByRut(string $rut): ?PersonaDTO
    {
        try {
            $resp = Http::withToken((string) config('services.personas_api.token'))
                // El valor por omisión es «desconocido» y NO el nombre de un sistema
                // real, que es lo que había antes («discapacidad»). Ese encabezado es
                // lo que el maestro guarda en `persona_lookups` como origen de la
                // consulta, así que un adoptante que olvidara configurarlo no quedaba
                // sin trazar: quedaba trazado como OTRO sistema. Una bitácora que
                // atribuye mal quién consultó los datos de un vecino es peor que una
                // que dice «no se sabe», y la Ley 21.719 pide trazabilidad de los
                // accesos, no una plausible.
                ->withHeaders(['X-Sistema' => (string) config('services.personas_api.sistema', 'desconocido')])
                ->acceptJson()
                ->timeout((int) config('services.personas_api.timeout', 5))
                ->get(rtrim((string) config('services.personas_api.url'), '/').'/api/servicios/v1/personas/'.urlencode($rut));
        } catch (ConnectionException $e) {
            throw MaestroNoDisponible::porExcepcion($e);
        }

        if ($resp->status() === 404) {
            return null;
        }

        try {
            $data = $resp->throw()->json('data');
        } catch (RequestException $e) {
            throw MaestroNoDisponible::porExcepcion($e);
        }

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
