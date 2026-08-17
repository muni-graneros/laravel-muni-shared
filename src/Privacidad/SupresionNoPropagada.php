<?php

namespace Muni\Shared\Privacidad;

use RuntimeException;

/**
 * La supresión no llegó al maestro de personas: o el sistema nunca declaró qué
 * debe pasar allá (no hay `PropagaSupresion` enlazado), o el maestro la
 * rechazó.
 *
 * En los dos casos la retención aborta ANTES de destruir el dato local, y esa
 * es la decisión de diseño que vale la pena dejar escrita: conservar de más es
 * una infracción; certificar una supresión que no ocurrió, mientras la
 * identidad sigue viva y consultable por RUT en el registro federado, es peor.
 *
 * Es RuntimeException y no DomainException por el mismo motivo que
 * `DiscoEvidenciaNoConfigurado`: no hay nada inválido en lo que se pidió, es el
 * entorno —la configuración del adoptante, o el maestro del otro lado— el que
 * no está en condiciones.
 */
class SupresionNoPropagada extends RuntimeException {}
