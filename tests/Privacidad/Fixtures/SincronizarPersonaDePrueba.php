<?php

namespace Muni\Shared\Tests\Privacidad\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Persona\WriteThrough\SincronizarAlMaestro;

/**
 * El write-through del ecosistema, tal como lo extiende cada sistema.
 *
 * Existe para poder ejercitar la interacción entre la retención y el maestro de
 * personas, que es donde apareció el defecto crítico: cada mitad estaba probada
 * por separado y las dos pasaban.
 */
class SincronizarPersonaDePrueba extends SincronizarAlMaestro
{
    protected function registro(): ?Model
    {
        return PersonaDePrueba::find($this->registroId);
    }

    /** @return array<string, mixed> */
    protected function payload(object $registro): array
    {
        return [
            'nro_documento' => $registro->documento,
            'nombres' => $registro->nombre,
        ];
    }

    protected function tabla(): string
    {
        return 'personas_de_prueba';
    }

    protected function sistema(): string
    {
        return 'prueba';
    }
}
