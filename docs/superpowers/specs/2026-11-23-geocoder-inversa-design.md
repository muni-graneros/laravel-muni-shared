# Geocodificación inversa en `Geocoder` (2026-11-23)

## Para qué
seguridad-graneros quiere que la denuncia ciudadana sea más rápida: si el vecino comparte su ubicación (gesto explícito),
la dirección se rellena sola. `Geocoder` hoy solo va de texto a coordenadas (`buscar`, `sugerencias`); falta el camino
inverso.

## Contrato
```php
/** @return array{dir: string, label: string, comuna: string}|null */
public static function inversa(float $lat, float $lng): ?array
```
- `dir`: calle y número (o el nombre del lugar), para el campo dirección. Mismas claves que una sugerencia decodificada.
- `label`: `dir` + comuna, para mostrar.
- `comuna`: ciudad/pueblo/comuna de OSM, para que el sistema decida si el punto está en Graneros.
- `null` ante cualquier cosa que no sea un acierto: coordenadas inválidas, fuera de Chile, sin calle, proveedor caído,
  respuesta no OK (429 incluido) o límite local alcanzado. **Nunca lanza**: se llama desde una petición web interactiva
  y el flujo escrito sigue sin bloquear.

## Política (la misma de la clase)
- Host fijo en constante (`https://nominatim.openstreetmap.org/reverse`), nunca desde el cliente. Mismo User-Agent.
- Mismo bucket de rate limit `geocoder:nominatim` (60 por 60 s, ≈1/s, política de Nominatim).
- Timeout 8 s. Caché 30 días solo para aciertos; un fallo o un «no hay dirección» no se guarda.

## Privacidad (Ley 21.719)
- La ubicación precisa del vecino es dato sensible. Se minimiza: las coordenadas se **redondean a 4 decimales (~11 m)**
  antes de salir a Nominatim y para la clave de caché (que además es un md5). Basta para una dirección.
- A Nominatim viajan solo `lat`, `lon`, `format`, `zoom`, `addressdetails` y `accept-language`. Nada de la persona.
- Los logs nunca llevan coordenadas: solo la clase de la excepción o el status HTTP (Guzzle arma su mensaje con la URI).

## Validación
- `lat` en [-90, 90], `lng` en [-180, 180], finitos (`NAN`/`INF` fuera). Inválidas → `null` sin salir a la red.
- Solo resultados con `address.country_code === 'cl'`.

## Fuera de alcance
El endpoint, el botón y el combobox de seguridad-graneros (carril aparte, en el sistema).
