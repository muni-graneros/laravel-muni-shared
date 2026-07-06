<?php

namespace Muni\Shared\Persona;

/**
 * Contrato del resolvedor de personas (compartido por el ecosistema).
 *
 * ⚠️ INTERFAZ SAGRADA — nunca se modifica.
 *
 * Cualquier implementación (BD local hoy, API centralizada mañana, o el sistema
 * de otro municipio) cumple este contrato. Los sistemas consumidores dependen
 * SOLO de esta interfaz, así que cambiar la fuente de datos es cambiar una línea
 * en el ServiceProvider, sin tocar formularios, controladores ni vistas.
 */
interface PersonaResolverInterface
{
    /**
     * Buscar persona por RUT en cualquier fuente disponible.
     * Retorna null si no existe.
     */
    public function findByRut(string $rut): ?PersonaDTO;

    /**
     * Verificar si un RUT ya existe en algún sistema.
     */
    public function existsByRut(string $rut): bool;

    /**
     * Retorna el origen del dato encontrado.
     * Útil para mostrar al funcionario de dónde vienen los datos.
     *
     * @return string 'local' | 'discapacidad' | 'feria' | 'api'
     */
    public function getSource(): string;
}
