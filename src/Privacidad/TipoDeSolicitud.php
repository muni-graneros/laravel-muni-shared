<?php

namespace Muni\Shared\Privacidad;

/** Los derechos del titular: acceso, rectificación, supresión, oposición y portabilidad. */
enum TipoDeSolicitud: string
{
    case Acceso = 'acceso';
    case Rectificacion = 'rectificacion';
    case Supresion = 'supresion';
    case Oposicion = 'oposicion';
    case Portabilidad = 'portabilidad';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Acceso => 'Acceso',
            self::Rectificacion => 'Rectificación',
            self::Supresion => 'Supresión',
            self::Oposicion => 'Oposición',
            self::Portabilidad => 'Portabilidad',
        };
    }

    /**
     * Acoger este derecho, ¿reanuda el tratamiento que el bloqueo preventivo
     * suspendió, o lo hace cesar?
     *
     * La pregunta existe porque la respuesta NO es la misma para todos, y
     * tratarla como si lo fuera —levantar el bloqueo se resuelva como se
     * resuelva— dejaba una oposición acogida con el tratamiento reanudado: al
     * revés del derecho que se acababa de reconocer.
     *
     * - **Rectificación**: reanuda. El bloqueo suspendió el tratamiento
     *   mientras el dato estaba en disputa; corregido el dato, la disputa
     *   terminó y el registro vuelve a ser tratable.
     * - **Oposición**: cesa. Acogerla es decirle al titular que el municipio
     *   deja de tratar ese dato para esa finalidad, y eso es precisamente lo
     *   que el bloqueo registra.
     * - **Supresión**: cesa. Cuando la supresión no puede ser total —hay una
     *   finalidad por función legal con plazo vigente— lo que queda en su
     *   lugar es el cese sobre las finalidades en que sí procede, y ese cese
     *   son bloqueos ligados a esta misma solicitud (`Supresiones`).
     *
     * Para acceso y portabilidad la respuesta es inocua y no está verificada
     * contra ningún bloqueo real: `Solicitudes::registrar()` no bloquea por
     * esos dos derechos —no ponen nada en disputa—, así que no hay nada que
     * levantar ni que volver definitivo. Dicen «reanuda» porque es lo que
     * corresponde a un derecho que no suspende ningún tratamiento, no porque
     * se haya medido el efecto sobre un bloqueo que nunca existe.
     */
    public function acogerloReanudaElTratamiento(): bool
    {
        return match ($this) {
            self::Rectificacion, self::Acceso, self::Portabilidad => true,
            self::Oposicion, self::Supresion => false,
        };
    }
}
