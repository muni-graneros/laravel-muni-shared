<?php

namespace Muni\Shared\Privacidad\Contratos;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo implementa cada sistema que sea modelo de lectura del maestro de personas,
 * normalmente envolviendo `Muni\Shared\Persona\WriteThrough\SincronizarAlMaestro`.
 *
 * Devuelve false si el maestro no aceptó el cambio; en ese caso la rectificación
 * completa se revierte.
 */
interface PropagaRectificacion
{
    /** @param array<string, mixed> $cambios */
    public function propagar(Model $titular, array $cambios): bool;
}
