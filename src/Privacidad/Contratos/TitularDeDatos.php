<?php

namespace Muni\Shared\Privacidad\Contratos;

/**
 * Lo implementa el modelo que representa a la persona en cada sistema
 * (`Persona` en los municipales, `User` donde corresponda).
 *
 * El módulo no conoce el modelo de datos de nadie: pide lo que sigue y el
 * sistema decide qué significan en su esquema. (Sin número en esta frase a
 * propósito: decía "cinco" cuando ya eran seis métodos.)
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

    /**
     * Los campos que el titular puede corregir mediante el derecho de
     * rectificación. No es un cheque en blanco sobre el registro: una persona
     * corrige su apellido, no su historial de trámites. Cada sistema declara
     * su propia lista porque solo él sabe qué de su esquema es "dato personal
     * corregible por el titular" y qué debe permanecer intacto.
     *
     * @return list<string> nombres de columna
     */
    public function camposRectificables(): array;

    /**
     * Fecha de nacimiento del titular, o null si el sistema no la tiene
     * acreditada.
     *
     * `null` NO significa adulto: significa que la edad no está acreditada.
     * La ley da régimen reforzado a los NNA, y un registro que asume mayoría de
     * edad por omisión trata a un menor como si pudiera consentir solo. Es el
     * mismo criterio que ya usa `Brecha::riesgo_alto`, donde null es "todavía
     * sin evaluar" y no "sin riesgo".
     *
     * Quién es NNA lo decide el módulo (`Edades`) y no cada sistema: es una
     * regla legal, y reimplementada ocho veces se equivoca ocho veces. El
     * sistema solo aporta el dato; la frontera de los 18 años vive acá.
     */
    public function fechaNacimientoTitular(): ?\DateTimeInterface;
}
