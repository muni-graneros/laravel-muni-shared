<?php

namespace Muni\Shared\Privacidad;

use RuntimeException;

/**
 * El disco no pudo borrar un documento que la anonimización tenía que suprimir.
 *
 * Es RuntimeException y no DomainException porque no hay nada inválido en lo que
 * se pidió: el estado del entorno —permisos, montaje, disco mal configurado— es
 * lo que impide cumplirlo. Se reintenta cuando el entorno se arregla.
 *
 * Se lanza en vez de contar el fallo y seguir, y ese es el punto de la clase:
 * seguir dejaría el documento vivo en disco con su ruta anulada —dato personal
 * sin dueño ni forma de encontrarlo— y una constancia afirmando que se suprimió.
 * El mensaje no incluye las rutas: el nombre del archivo suele traer el RUT, y
 * esto termina en el log del cron.
 */
class ArchivoNoSuprimido extends RuntimeException {}
