<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Se intentó registrar que se informó al titular con un texto que no existe.
 *
 * Falla en vez de registrar una entrega vacía: un registro que dice "se informó"
 * sin poder decir qué se informó es peor que no tener registro, porque parece
 * cumplimiento.
 */
class TextoNoPublicado extends DomainException {}
