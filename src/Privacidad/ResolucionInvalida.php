<?php

namespace Muni\Shared\Privacidad;

use RuntimeException;

/**
 * Cubre las dos formas de resolver mal una solicitud: sin fundamento, o
 * reabriendo una que ya se cerró. Es su propio tipo, y no un `RuntimeException`
 * genérico, para que quien resuelve solicitudes pueda distinguir "esto era
 * evitable con mejor UI" (por ejemplo, no dejar reenviar el mismo formulario)
 * de cualquier otra falla del sistema.
 */
class ResolucionInvalida extends RuntimeException {}
