<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Se construyó un `ResultadoDePropagacion` que no dice lo que tiene que decir:
 * hoy, un «no correspondía propagar» sin motivo escrito.
 *
 * `DomainException` y no `RuntimeException` porque no falla el entorno: falla
 * lo que el adoptante declaró. Y truena en vez de guardar un motivo vacío
 * porque el motivo es lo único que separa el tercer estado de la mentira que
 * vino a reemplazar —«no propagué» sin decir por qué es indistinguible de «no
 * me acordé de propagar»—.
 */
class PropagacionInvalida extends DomainException {}
