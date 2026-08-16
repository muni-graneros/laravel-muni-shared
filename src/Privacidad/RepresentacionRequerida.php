<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * El titular es NNA y se pidió que actuara solo.
 *
 * Se separó de `EdadNoAcreditada`, y la razón no es de prolijidad: las dos
 * excepciones piden acciones DISTINTAS del funcionario que las ve. Con la edad
 * sin acreditar, el mesón tiene que pedir un documento que pruebe la fecha de
 * nacimiento; acá la edad ESTÁ acreditada y lo que falta es que firme el
 * representante legal. Mientras compartieron clase, un panel que siguiera el
 * consejo de aquel docblock —«ofrecer acreditar la edad»— mandaba a buscar un
 * certificado de nacimiento cuando lo que correspondía era llamar a la madre o
 * al padre. Que ningún test las distinguiera se comprobó intercambiándoles los
 * mensajes: la suite quedó verde.
 *
 * Tampoco sirve el apoderado, y no es un olvido: un menor no puede otorgar
 * mandato, así que un apoderado suyo no existe jurídicamente.
 */
class RepresentacionRequerida extends DomainException {}
