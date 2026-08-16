<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Dos publicaciones del mismo texto se pisaron y no se pudo resolver.
 *
 * `Textos::publicar()` calcula el número de versión leyendo el máximo y después
 * inserta; entre esas dos cosas cabe otra publicación del mismo
 * `(sistema, codigo)`. El índice único impide que queden dos versiones con el
 * mismo número —o sea, la evidencia nunca se corrompe—, pero el que pierde la
 * carrera se lleva el choque. Reintentar con el número recalculado resuelve el
 * caso normal; esta excepción es lo que queda cuando ni así se pudo, y existe
 * para que el adoptante reciba un error de dominio como en el resto del módulo
 * y no una `QueryException` cruda del driver.
 */
class PublicacionEnConflicto extends DomainException {}
