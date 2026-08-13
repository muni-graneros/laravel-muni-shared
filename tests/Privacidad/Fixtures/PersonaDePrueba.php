<?php

namespace Muni\Shared\Tests\Privacidad\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

/**
 * Titular mínimo para ejercitar el módulo sin depender del esquema de ningún
 * sistema real. Refleja lo que hace `Persona` en los municipales.
 */
class PersonaDePrueba extends Model implements TitularDeDatos
{
    protected $table = 'personas_de_prueba';

    protected $guarded = [];

    public bool $sensiblesPurgados = false;

    public bool $fueAnonimizada = false;

    public function titularNombre(): string
    {
        return (string) $this->nombre;
    }

    public function titularDocumento(): string
    {
        return (string) $this->documento;
    }

    /** @return array<string, mixed> */
    public function exportarDatosPersonales(): array
    {
        return ['nombre' => $this->nombre, 'documento' => $this->documento];
    }

    public function purgarDatosSensibles(): void
    {
        $this->forceFill(['diagnostico' => null])->save();
        $this->sensiblesPurgados = true;
    }

    public function anonimizar(): void
    {
        $this->forceFill(['nombre' => 'ANONIMIZADO', 'documento' => null])->save();
        $this->fueAnonimizada = true;
    }
}
