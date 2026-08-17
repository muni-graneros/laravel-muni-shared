<?php

namespace Muni\Shared\Tests\Privacidad\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\SupresionEnCurso;

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

    /**
     * Qué veía `SupresionEnCurso` en el instante exacto de purgar y de
     * anonimizar. Es estático porque lo que importa medir no es esta instancia
     * sino el contexto del proceso: el observador `saved` del sistema adoptante
     * —que es quien despacha el write-through al maestro— consulta ese mismo
     * contexto de proceso, no el objeto.
     */
    public static ?bool $supresionActivaAlPurgar = null;

    public static ?bool $supresionActivaAlAnonimizar = null;

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
        self::$supresionActivaAlPurgar = SupresionEnCurso::activa();
        $this->forceFill(['diagnostico' => null])->save();
        $this->sensiblesPurgados = true;
    }

    public function anonimizar(): void
    {
        self::$supresionActivaAlAnonimizar = SupresionEnCurso::activa();
        $this->forceFill(['nombre' => 'ANONIMIZADO', 'documento' => null])->save();
        $this->fueAnonimizada = true;
    }

    /** @return list<string> */
    public function camposRectificables(): array
    {
        // Deliberadamente sin 'diagnostico': es el campo que un test debe
        // probar que se rechaza.
        return ['nombre', 'documento'];
    }

    public function fechaNacimientoTitular(): ?\DateTimeInterface
    {
        return $this->fecha_nacimiento
            ? Carbon::parse($this->fecha_nacimiento)
            : null;
    }
}
