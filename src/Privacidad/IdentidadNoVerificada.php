<?php

namespace Muni\Shared\Privacidad;

use RuntimeException;

/**
 * Entregar datos personales a quien no acreditó ser el titular es el error
 * más caro del módulo, y es el único que un llamador necesita distinguir de
 * cualquier otro `RuntimeException` para, por ejemplo, mostrar un mensaje
 * específico en el formulario en vez de un error genérico.
 *
 * También implementa `SolicitudRechazada`: sigue siendo `RuntimeException`
 * —quien la atrapaba así antes la sigue atrapando igual—, y además se puede
 * atrapar junto con las otras tres negativas de `Solicitudes::registrar()`
 * con un solo `catch (SolicitudRechazada $e)`.
 */
class IdentidadNoVerificada extends RuntimeException implements SolicitudRechazada {}
