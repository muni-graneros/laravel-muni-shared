<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Una opción que el módulo no puede honrar tal como viene.
 *
 * Existe por dos caminos que antes salían del vocabulario del módulo:
 *
 * - `otorgado_por` con un valor que no es un caso de `Solicitante`: reventaba
 *   con `ValueError` —o con `ErrorException` si venía un arreglo— desde las
 *   entrañas del cast, un fallo que ningún adoptante puede atrapar por nombre.
 * - `codigo_texto` y `version_texto`, que este módulo dejó de aceptar: el
 *   primero se resolvía al escribir y podía acreditar un texto que el titular
 *   nunca leyó; el segundo era un string suelto que no probaba nada. Se rechaza
 *   en vez de ignorarse en silencio, porque ignorar una opción que el adoptante
 *   escribió para acreditar algo lo deja creyendo que acreditó.
 *
 * `DomainException` como el resto: el estado que describe es del dominio —lo
 * que se pidió registrar no es registrable— y no un fallo de infraestructura.
 */
class OpcionInvalida extends DomainException {}
