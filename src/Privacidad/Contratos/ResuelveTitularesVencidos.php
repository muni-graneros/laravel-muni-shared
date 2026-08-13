<?php

namespace Muni\Shared\Privacidad\Contratos;

use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * Cada sistema sabe desde cuándo trata a un titular bajo una finalidad: puede
 * ser la fecha de registro, la última atención o el cierre del caso. El módulo
 * no puede adivinarlo, así que lo pregunta.
 */
interface ResuelveTitularesVencidos
{
    /** @return iterable<int, TitularDeDatos> */
    public function vencidos(Finalidad $finalidad): iterable;
}
