<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * El tratamiento exige saber si el titular es NNA y el sistema no tiene su
 * fecha de nacimiento.
 *
 * UNA sola cosa, y esto antes no era así: la clase cubría también «un menor
 * intentó consentir solo», que es un estado distinto y pide una acción
 * distinta del funcionario. Eso vive ahora en `RepresentacionRequerida`; acá
 * queda solo la edad desconocida, que es lo que el nombre dice.
 *
 * Falla en vez de asumir mayoría de edad. Los dos errores posibles no cuestan lo
 * mismo: equivocarse hacia acá cuesta que alguien tenga que ir a acreditar una
 * fecha de nacimiento; equivocarse hacia el otro lado es dar por válido el
 * consentimiento de un menor. Un registro comunal de discapacidad —el primer
 * consumidor del módulo— tiene menores con certeza.
 *
 * Es `DomainException` y no una excepción de validación porque el estado que
 * describe es del dominio: la edad no está acreditada. Quien la atrape en un
 * panel debería ofrecer acreditarla —pedir el documento con la fecha de
 * nacimiento—, no reintentar.
 *
 * También implementa `SolicitudRechazada`: sigue siendo `DomainException`
 * —quien la atrapaba así antes la sigue atrapando igual—, y además se puede
 * atrapar junto con las demás negativas de `Solicitudes::registrar()` (y de
 * `Consentimientos::otorgar()`, que la lanza por el mismo régimen de edad) con
 * un solo `catch (SolicitudRechazada $e)`.
 */
class EdadNoAcreditada extends DomainException implements SolicitudRechazada {}
