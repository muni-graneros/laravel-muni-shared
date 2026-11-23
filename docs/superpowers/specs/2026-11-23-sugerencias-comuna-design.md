# Sugerencias de dirección acotadas a una comuna

**Fecha:** 2026-11-23 · **Paquete:** `muni-graneros/laravel-muni-shared` · **Pedido:** César (vía dev-b4)

## Problema
`Geocoder::sugerencias()` sesga Photon hacia el centro de Graneros pero no restringe: al escribir una calle aparecen
sugerencias de Machalí y de otras comunas. Los formularios municipales que solo aceptan direcciones de Graneros
(denuncia ciudadana, emergencia, panel de incidentes de seguridad-graneros) necesitan descartarlas.

## Contrato
```php
/** @return list<array{valor: string, label: string}> */
public static function sugerencias(string $q, int $limite = 6, ?string $comuna = null): array
```
- `$comuna === null` (o vacía tras `trim`): comportamiento idéntico al de hoy (mismos parámetros a Photon, misma clave de
  caché).
- `$comuna` dada: solo se devuelven sugerencias cuya comuna coincide con `$comuna`, comparando normalizado (sin tildes,
  minúsculas, sin espacios de borde). La comuna de una sugerencia se busca en `city`, `district` y `locality` de
  Photon (en ese orden; coincide si cualquiera de ellos coincide). `county` no se usa para comparar: en Chile es la
  provincia.
- Si la comuna tiene un recuadro conocido (hoy solo Graneros: lon -70.9..-70.6, lat -34.2..-33.95) se envía además
  `bbox=minLon,minLat,maxLon,maxLat` a Photon. El recuadro mejora el recall, pero el filtro del servidor es el que
  garantiza el resultado.
- Con comuna se piden a Photon `$limite * 5` resultados (antes del filtro) para no quedarse corto; sin comuna se
  mantiene `$limite * 3`.

## Política (sin cambios)
Host fijo, mismo User-Agent, mismo tope `geocoder:photon`, timeout 6 s, logs sin el texto consultado ni la comuna,
caché de 12 h solo de respuestas reales. La clave de caché incluye la comuna normalizada cuando se pasa.

## Fuera de alcance
Cambiar `buscar()`, `inversa()` o el proveedor; catálogo local de calles; los formularios de seguridad-graneros.
