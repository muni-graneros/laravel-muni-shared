<?php

namespace Muni\Shared\Privacidad\Contratos;

/**
 * Lo implementa el modelo que representa a la persona en cada sistema
 * (`Persona` en los municipales, `User` donde corresponda).
 *
 * El módulo no conoce el modelo de datos de nadie: pide estas cinco cosas y el
 * sistema decide qué significan en su esquema.
 */
interface TitularDeDatos
{
    public function titularNombre(): string;

    public function titularDocumento(): string;

    /**
     * Los datos del titular para el derecho de acceso y el de portabilidad.
     *
     * @return array<string, mixed> estructura serializable a JSON y a PDF
     */
    public function exportarDatosPersonales(): array;

    /**
     * Borrado real de los datos sensibles, incluidos los archivos en disco.
     * No es soft delete: la ley pide supresión.
     */
    public function purgarDatosSensibles(): void;

    /**
     * Deja el registro sin capacidad de reidentificación, conservando lo que
     * sirve para estadística comunal.
     */
    public function anonimizar(): void;
}
