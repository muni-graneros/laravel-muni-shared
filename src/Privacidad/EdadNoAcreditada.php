<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * El tratamiento exige saber si el titular es NNA y no se sabe, o se pidió el
 * consentimiento directo de un menor de edad.
 *
 * Falla en vez de asumir mayoría de edad. Los dos errores posibles no cuestan lo
 * mismo: equivocarse hacia acá cuesta que alguien tenga que ir a acreditar una
 * fecha de nacimiento; equivocarse hacia el otro lado es dar por válido el
 * consentimiento de un menor. Un registro comunal de discapacidad —el primer
 * consumidor del módulo— tiene menores con certeza.
 *
 * Es `DomainException` y no una excepción de validación porque el estado que
 * describe es del dominio: la edad no está acreditada. Quien la atrape en un
 * panel debería ofrecer acreditarla, no reintentar.
 */
class EdadNoAcreditada extends DomainException {}
