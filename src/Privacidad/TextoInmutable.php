<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Se lanza al intentar alterar un texto ya publicado.
 *
 * Editarlo dejaría sin sentido a todos los consentimientos que lo apuntan: no
 * se podría decir qué leyó la persona al aceptar. Para cambiar el texto se
 * publica una versión nueva.
 */
class TextoInmutable extends DomainException {}
