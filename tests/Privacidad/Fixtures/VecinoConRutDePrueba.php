<?php

namespace Muni\Shared\Tests\Privacidad\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

/**
 * Un titular cuya clave primaria NO es un número.
 *
 * Existe porque hay sistemas del ecosistema que identifican a la persona por su
 * RUT y lo usan como clave primaria. Si el módulo solo aceptara claves
 * numéricas, en esos sistemas el morph guardaría un titular equivocado —MariaDB
 * trunca «11111111-1» a 11111111— y el expediente de un vecino terminaría
 * apuntando a otro.
 */
class VecinoConRutDePrueba extends Model implements TitularDeDatos
{
    protected $table = 'vecinos_con_rut_de_prueba';

    protected $primaryKey = 'rut';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function titularNombre(): string
    {
        return (string) $this->nombre;
    }

    public function titularDocumento(): string
    {
        return (string) $this->rut;
    }

    /** @return array<string, mixed> */
    public function exportarDatosPersonales(): array
    {
        return ['rut' => $this->rut, 'nombre' => $this->nombre];
    }

    public function purgarDatosSensibles(): void
    {
        $this->forceFill(['observacion' => null])->save();
    }

    public function anonimizar(): void
    {
        $this->forceFill(['nombre' => 'Anonimizado'])->save();
    }

    /** @return array<int, string> */
    public function camposRectificables(): array
    {
        return ['nombre'];
    }

    public function fechaNacimientoTitular(): ?\DateTimeInterface
    {
        return $this->fecha_nacimiento ? new \DateTimeImmutable((string) $this->fecha_nacimiento) : null;
    }
}
