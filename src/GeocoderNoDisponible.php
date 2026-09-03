<?php

namespace Muni\Shared;

/**
 * El geocodificador no pudo preguntarle al proveedor: red caída, respuesta no
 * OK o el límite local de peticiones alcanzado.
 *
 * Existe para que «no pude preguntar» deje de parecerse a «pregunté y no
 * existe». Con un solo `null` para ambos, un corte de Nominatim dejaba
 * requerimientos sin ubicar en silencio: el job terminaba bien, no reintentaba
 * y nadie lo veía. Quien llama desde una cola deja subir esta excepción y la
 * cola reintenta con backoff; quien no puede permitirse una excepción usa
 * `Geocoder::buscar()`, que la captura y devuelve `null` como siempre.
 *
 * No lleva la excepción del cliente HTTP como `previous`, a propósito: Guzzle
 * arma su mensaje con la URI completa, y en la query va la dirección del
 * vecino. Cuando un job agota los reintentos, Laravel reporta la excepción con
 * su cadena entera —laravel.log y GlitchTip incluidos—, así que encadenarla
 * sería mandar la dirección al log de errores. El mensaje dice qué clase de
 * fallo fue; para más detalle está el `Log::warning` que se escribe al fallar.
 */
final class GeocoderNoDisponible extends \RuntimeException {}
