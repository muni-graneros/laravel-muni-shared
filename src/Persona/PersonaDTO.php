<?php

namespace Muni\Shared\Persona;

use Muni\Shared\RutHelper;

/**
 * Representación canónica e inmutable de una persona, independiente de la fuente.
 *
 * El `nroDocumento` siempre se devuelve normalizado ("14523681-9") sin importar
 * cómo estaba almacenado, para que los consumidores no dependan del formato de
 * origen.
 *
 * NOTA: la construcción desde un modelo Eloquent (`fromModel`) vive en el
 * `LocalPersonaResolver` de cada sistema, porque el modelo `Persona` y sus
 * relaciones son específicos de cada dominio. Aquí solo queda el DTO neutro.
 */
class PersonaDTO
{
    public function __construct(
        public readonly string $nroDocumento,
        public readonly string $nombres,
        public readonly string $apellidos,
        public readonly ?string $fechaNacimiento,
        public readonly ?string $sexo,
        public readonly ?string $telefono,
        public readonly ?string $email,
        public readonly ?string $direccion,
        public readonly ?string $sector,
        public readonly string $source,  // de dónde vino el dato: discapacidad|feria|local|api
        public readonly string $system,  // en qué sistema estaba (clave)
        public readonly ?string $fechaRegistro = null,
        public readonly ?string $tipoDocumento = null,  // RUN | PAS | DNI_EXT | …
    ) {}

    public static function fromArray(array $data): self
    {
        $source = $data['source'] ?? 'api';

        return new self(
            nroDocumento: RutHelper::normalize($data['nro_documento'] ?? ''),
            nombres: $data['nombres'] ?? '',
            apellidos: $data['apellidos'] ?? '',
            fechaNacimiento: $data['fecha_nacimiento'] ?? null,
            sexo: $data['sexo'] ?? null,
            telefono: $data['telefono'] ?? null,
            email: $data['email'] ?? null,
            direccion: $data['direccion'] ?? null,
            sector: $data['sector'] ?? null,
            source: $source,
            system: $data['system'] ?? $source,
            fechaRegistro: $data['fecha_registro'] ?? null,
            tipoDocumento: $data['tipo_documento'] ?? null,
        );
    }

    /** Payload de datos de la persona (el bloque `data` de la respuesta del endpoint). */
    public function toArray(): array
    {
        return [
            'nro_documento' => $this->nroDocumento,
            'tipo_documento' => $this->tipoDocumento,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'fecha_nacimiento' => $this->fechaNacimiento,
            'sexo' => $this->sexo,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'direccion' => $this->direccion,
            'sector' => $this->sector,
            'fecha_registro' => $this->fechaRegistro,
        ];
    }

    /** Nombre legible del sistema de origen, para mostrar al funcionario. */
    public function systemLabel(): string
    {
        return self::label($this->system);
    }

    public static function label(string $key): string
    {
        return match ($key) {
            // 'omil' se mantiene por retrocompatibilidad con datos históricos.
            'discapacidad', 'omil' => 'Discapacidad',
            'feria' => 'Feria Control',
            'api' => 'API Central de Personas',
            default => 'Sistema Municipal',
        };
    }
}
