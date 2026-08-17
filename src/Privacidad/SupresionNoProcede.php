<?php

namespace Muni\Shared\Privacidad;

use RuntimeException;

/**
 * El titular pidió la supresión y el RAT dice que todavía no procede sobre
 * ninguna finalidad: todas las que lo alcanzan se fundan en una norma
 * habilitante y su plazo de conservación sigue corriendo.
 *
 * Implementa `SolicitudRechazada` porque es exactamente eso: una negativa con
 * razón legal, dirigida a una persona que está al otro lado del mesón, y su
 * mensaje —que lleva la norma y el plazo— es lo que hay que mostrarle. No es un
 * error de integración del adoptante ni una falla de infraestructura.
 *
 * Lo que este módulo NO hace, y es deliberado: **no rechaza la solicitud por su
 * cuenta**. Deja la solicitud en trámite y truena. Rechazar es una resolución
 * fundada que le responde a un ciudadano y que alguien firma; escribirla desde
 * acá significaría inventar el fundamento de una resolución administrativa, que
 * es el mismo defecto —un registro que dice que el municipio decidió algo que
 * no decidió— por el que existe el resto de este módulo.
 */
class SupresionNoProcede extends RuntimeException implements SolicitudRechazada {}
