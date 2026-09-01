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
 */
final class GeocoderNoDisponible extends \RuntimeException {}
