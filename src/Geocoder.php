<?php

namespace Muni\Shared;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Geocodificación con Nominatim (OpenStreetMap): convierte una dirección de texto
 * en coordenadas. Gratis y sin API key.
 *
 * Política de uso de Nominatim (https://operations.osmfoundation.org/policies/nominatim/):
 *   - Máx. 1 petición por segundo (el comando de lote respeta la pausa).
 *   - User-Agent identificable obligatorio (lo enviamos abajo).
 * Para uso intensivo conviene auto-hospedar Nominatim.
 */
final class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    private const USER_AGENT = 'SistemasMunicipalesGraneros/1.0 (https://municipalidadgraneros.cl)';

    // Photon (OSM) para autocompletado tipo Google Maps. Gratis y sin API key.
    private const PHOTON_ENDPOINT = 'https://photon.komoot.io/api/';

    // Centro de Graneros: sesga las sugerencias a la zona (no las restringe a la
    // comuna, así también encuentra direcciones de comunas vecinas como Machalí).
    private const GRANEROS_LAT = -34.0664;

    private const GRANEROS_LON = -70.7297;

    /**
     * Busca coordenadas para una dirección dentro de Graneros, Chile.
     *
     * Devuelve `null` tanto si la dirección no existe como si no se pudo
     * preguntar: es el contrato que todos los sistemas del ecosistema esperan
     * desde el principio y se conserva. Quien necesite distinguir los dos casos
     * (un job en cola que debe reintentar si el proveedor está caído) usa
     * {@see buscarEstricto()}.
     *
     * @return array{lat: float, lng: float, nombre: string, aproximado: bool}|null
     */
    public static function buscar(?string $direccion, ?string $sector = null): ?array
    {
        try {
            return self::buscarEstricto($direccion, $sector);
        } catch (GeocoderNoDisponible) {
            // Ya quedó en el log al fallar la consulta; acá solo se preserva el
            // contrato de siempre.
            return null;
        }
    }

    /**
     * Igual que {@see buscar()}, pero «no encontré la dirección» y «no pude
     * preguntar» dejan de parecerse: `null` solo cuando Nominatim respondió y no
     * conoce la dirección; {@see GeocoderNoDisponible} cuando no hubo respuesta
     * (red caída, error del proveedor o límite local de peticiones alcanzado).
     *
     * Intenta en cascada (de más preciso a más aproximado) porque OSM no siempre
     * tiene el número de calle en pueblos chicos: dirección completa → sin el
     * número → solo el sector. Devuelve la primera que resuelva, marcando si es
     * aproximada para que la UI invite a ajustar el pin. Si una consulta de la
     * cascada falla por el proveedor, no se sigue con las demás: la red caída no
     * se arregla probando con menos texto.
     *
     * @return array{lat: float, lng: float, nombre: string, aproximado: bool}|null
     *
     * @throws GeocoderNoDisponible
     */
    public static function buscarEstricto(?string $direccion, ?string $sector = null): ?array
    {
        $direccion = trim((string) $direccion);
        $sector = trim((string) $sector);

        if ($direccion === '' && $sector === '') {
            return null;
        }

        // Auditoría 3.5 — Caché por dirección normalizada: una dirección ya resuelta
        // no se vuelve a pedir a Nominatim. Solo se cachean aciertos: ni el «no
        // existe» ni —sobre todo— un fallo del proveedor, que de quedar guardado
        // convertiría un corte de un minuto en 30 días de «esa calle no está».
        // TTL largo: la geolocalización de una calle no cambia.
        $clave = 'geocoder:'.md5(mb_strtolower($direccion.'|'.$sector));

        $cacheado = Cache::get($clave);
        if (is_array($cacheado)) {
            return $cacheado;
        }

        $resultado = self::resolver($direccion, $sector);

        if ($resultado !== null) {
            Cache::put($clave, $resultado, now()->addDays(30));
        }

        return $resultado;
    }

    /**
     * Sugerencias de direcciones para autocompletado (tipo Google Maps), vía Photon.
     * Sesgadas a Graneros y filtradas a Chile. Cada sugerencia ya trae coordenadas,
     * así que al elegirla NO hace falta volver a geocodificar (resuelve precisión y UX).
     *
     * @return list<array{valor: string, label: string}>
     */
    public static function sugerencias(string $q, int $limite = 6): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 3) {
            return [];
        }

        // Caché por consulta normalizada: el type-ahead repite mucho los mismos prefijos.
        $clave = 'geocoder:sug:'.md5(mb_strtolower($q));

        $cacheadas = Cache::get($clave);
        if (is_array($cacheadas)) {
            return $cacheadas;
        }

        $sugerencias = self::consultarPhoton($q, $limite);

        // Un fallo (red, error del proveedor, límite local) devuelve lista vacía
        // al que escribe, pero NO se guarda: cacheado 12 horas, un corte de un
        // minuto dejaba ese prefijo sin sugerencias medio día. Una lista vacía
        // que Photon devolvió de verdad sí se guarda: es un «no hay» legítimo.
        if ($sugerencias === null) {
            return [];
        }

        Cache::put($clave, $sugerencias, now()->addHours(12));

        return $sugerencias;
    }

    /**
     * Decodifica el valor de una sugerencia elegida (lat/lng/dirección).
     *
     * @return array{lat: float, lng: float, dir: string, label: string}|null
     */
    public static function decodificarSugerencia(?string $valor): ?array
    {
        if (! $valor) {
            return null;
        }

        $json = json_decode((string) base64_decode($valor, true), true);

        return is_array($json) && isset($json['lat'], $json['lng']) ? $json : null;
    }

    /**
     * `null` cuando no se pudo preguntar (para que el que llama no lo cachee);
     * lista —vacía o no— cuando Photon respondió.
     *
     * @return list<array{valor: string, label: string}>|null
     */
    private static function consultarPhoton(string $q, int $limite): ?array
    {
        // Etiqueta de uso responsable de Photon: tope ~120 consultas/min.
        if (RateLimiter::tooManyAttempts('geocoder:photon', 120)) {
            Log::warning('Geocoder: límite de peticiones a Photon alcanzado; se omiten sugerencias.');

            return null;
        }
        RateLimiter::hit('geocoder:photon', 60);

        try {
            $respuesta = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(6)
                ->get(self::PHOTON_ENDPOINT, [
                    'q' => $q,
                    'limit' => $limite * 3, // pedimos de más para filtrar a Chile y quedarnos con $limite
                    // Sesgo por cercanía a Graneros: prioriza la zona PERO no la restringe,
                    // así también aparecen direcciones de todo Chile (Santiago, etc.).
                    'lat' => self::GRANEROS_LAT,
                    'lon' => self::GRANEROS_LON,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Geocoder: fallo de red al consultar Photon.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $respuesta->ok()) {
            Log::warning('Geocoder: respuesta no OK de Photon.', ['status' => $respuesta->status()]);

            return null;
        }

        $items = [];

        foreach ((array) $respuesta->json('features') as $f) {
            $p = $f['properties'] ?? [];
            $coords = $f['geometry']['coordinates'] ?? null; // [lon, lat]

            if (($p['countrycode'] ?? null) !== 'CL' || ! is_array($coords)) {
                continue;
            }

            // Dirección corta: calle + número, o el nombre del lugar.
            $calle = trim(($p['street'] ?? $p['name'] ?? '').' '.($p['housenumber'] ?? ''));
            if ($calle === '') {
                continue;
            }

            $ciudad = $p['city'] ?? $p['district'] ?? $p['county'] ?? '';
            $label = trim($calle.($ciudad !== '' ? ', '.$ciudad : ''));
            $lat = round((float) $coords[1], 7);
            $lng = round((float) $coords[0], 7);

            $items[] = [
                'valor' => base64_encode((string) json_encode([
                    'lat' => $lat, 'lng' => $lng, 'dir' => $calle, 'label' => $label,
                ])),
                'label' => $label,
            ];

            if (count($items) >= $limite) {
                break;
            }
        }

        return $items;
    }

    /** Cascada de consultas (de más precisa a más aproximada). */
    private static function resolver(string $direccion, string $sector): ?array
    {
        // Dirección sin el número final (ej: "Los Quintos 034" → "Los Quintos").
        $sinNumero = trim((string) preg_replace('/\s*\d+\s*$/', '', $direccion));

        $consultas = array_values(array_unique(array_filter([
            self::armar($direccion, $sector),                                  // completa + sector
            $sector !== '' ? self::armar($direccion, null) : null,             // completa sin sector
            ($sinNumero !== '' && $sinNumero !== $direccion) ? self::armar($sinNumero, $sector) : null, // sin número
            $sector !== '' ? self::armar($sector, null) : null,                // solo el sector
        ])));

        foreach ($consultas as $i => $consulta) {
            $r = self::consultar($consulta);
            if ($r !== null) {
                $r['aproximado'] = $i > 0; // el primer intento es el más exacto

                return $r;
            }
        }

        return null;
    }

    /** Arma la cadena de búsqueda con la comuna/región/país. */
    private static function armar(string $texto, ?string $sector): string
    {
        $partes = array_filter([
            trim($texto),
            trim((string) $sector),
            'Graneros',
            'Región de O\'Higgins',
            'Chile',
        ]);

        return implode(', ', $partes);
    }

    /**
     * Una consulta a Nominatim. `null` solo cuando respondió y no encontró nada.
     *
     * @return array{lat: float, lng: float, nombre: string}|null
     *
     * @throws GeocoderNoDisponible si no se pudo preguntar
     */
    private static function consultar(string $consulta): ?array
    {
        // Auditoría 3.5 — Rate limit global: máx. ~60 peticiones por ventana de 60s
        // (≈1/seg, la política de uso de Nominatim). Si se supera, abortamos en vez
        // de arriesgar el baneo de la IP municipal.
        if (RateLimiter::tooManyAttempts('geocoder:nominatim', 60)) {
            // Auditoría 6.2 — visibilidad operacional: avisamos en el log si tocamos
            // el límite de Nominatim (señal de uso anómalo o ráfaga de geocodificación).
            Log::warning('Geocoder: límite de peticiones a Nominatim alcanzado; se omite la consulta.');

            throw new GeocoderNoDisponible('Límite local de peticiones a Nominatim alcanzado; reintentar más tarde.');
        }
        RateLimiter::hit('geocoder:nominatim', 60);

        try {
            $respuesta = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(8)
                ->get(self::ENDPOINT, [
                    'q' => $consulta,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'cl',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Geocoder: fallo de red al consultar Nominatim.', ['error' => $e->getMessage()]);

            throw new GeocoderNoDisponible('Fallo de red al consultar Nominatim.', 0, $e);
        }

        if (! $respuesta->ok()) {
            Log::warning('Geocoder: respuesta no OK de Nominatim.', ['status' => $respuesta->status()]);

            throw new GeocoderNoDisponible("Nominatim respondió {$respuesta->status()}.");
        }

        $primero = $respuesta->json()[0] ?? null;
        if (! is_array($primero) || ! isset($primero['lat'], $primero['lon'])) {
            return null;
        }

        return [
            'lat' => round((float) $primero['lat'], 7),
            'lng' => round((float) $primero['lon'], 7),
            'nombre' => (string) ($primero['display_name'] ?? $consulta),
        ];
    }
}
