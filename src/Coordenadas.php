<?php

namespace Muni\Shared;

/**
 * Parser de coordenadas pegadas desde Google Maps.
 *
 * Acepta los formatos habituales que produce Google al copiar:
 *   - "−33.4489, −70.6693"        (clic derecho → copiar coordenadas)
 *   - "-33.4489,-70.6693"
 *   - URL con "@lat,lng,zoomz"     (barra de direcciones del mapa)
 *   - URL con "?q=lat,lng" o "&query=lat,lng"
 *   - URL de lugar con "!3dLAT!4dLNG"
 *
 * Devuelve ['lat' => float, 'lng' => float] o null si no logra extraer un par
 * de coordenadas válido (lat ∈ [-90,90], lng ∈ [-180,180]).
 */
final class Coordenadas
{
    public static function parse(?string $entrada): ?array
    {
        if ($entrada === null) {
            return null;
        }

        $texto = trim($entrada);
        if ($texto === '') {
            return null;
        }

        // Normaliza el signo "menos" tipográfico (−, U+2212) al ASCII '-'.
        $texto = str_replace("\u{2212}", '-', $texto);

        $patrones = [
            '/@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/',           // .../@lat,lng,17z
            '/[?&](?:q|query|ll|destination)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/', // ?q=lat,lng
            '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/',          // !3dLAT!4dLNG
            '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', // "lat, lng" a secas
        ];

        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $m)) {
                return self::validar((float) $m[1], (float) $m[2]);
            }
        }

        return null;
    }

    private static function validar(float $lat, float $lng): ?array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }
}
