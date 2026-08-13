<?php

namespace Muni\Shared\Privacidad;

use RuntimeException;

/**
 * Entregar datos personales a quien no acreditó ser el titular es el error
 * más caro del módulo, y es el único que un llamador necesita distinguir de
 * cualquier otro `RuntimeException` para, por ejemplo, mostrar un mensaje
 * específico en el formulario en vez de un error genérico.
 */
class IdentidadNoVerificada extends RuntimeException {}
