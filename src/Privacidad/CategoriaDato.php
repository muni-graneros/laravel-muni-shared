<?php

namespace Muni\Shared\Privacidad;

/**
 * Vocabulario cerrado de categorías de datos que una finalidad puede declarar
 * en `categorias_datos`.
 *
 * Antes de este enum, la columna era un array de strings libres y la
 * invariante de dato sensible hacía `array_intersect` contra ocho literales
 * exactos: cualquier variante -mayúscula, sinónimo, error de tipeo- pasaba de
 * largo y quedaba tratada como NO sensible en silencio. `'discapacidad'`
 * escrita por un funcionario nunca coincidía con `'salud'`, y el registro que
 * este módulo existe para proteger quedaba sin la excepción legal declarada.
 * Un enum backed hace el catálogo total: lo que no está en la lista no se
 * guarda, no se cuela.
 */
enum CategoriaDato: string
{
    // No sensibles: las únicas que hoy aparecen documentadas en las
    // especificaciones y en los ejemplos de adopción de este paquete.
    case Identificacion = 'identificacion';
    case Contacto = 'contacto';

    // Sensibles: el catálogo que antes vivía en `Finalidad::CATEGORIAS_SENSIBLES`,
    // ahora la única fuente de verdad de qué es sensible es este enum.
    case Salud = 'salud';

    /**
     * Es la categoría central del registro de discapacidad -el propio motivo
     * de ser de este módulo- y datos de salud según la ley. Es también la
     * palabra que un funcionario municipal escribe más probablemente: por
     * eso necesita su propio caso y no puede depender de que alguien recuerde
     * escribir `salud` en su lugar.
     */
    case Discapacidad = 'discapacidad';

    case Biometricos = 'biometricos';
    case PerfilBiologico = 'perfil_biologico';
    case OrigenEtnico = 'origen_etnico';
    case Socioeconomico = 'socioeconomico';
    case VidaSexual = 'vida_sexual';
    case Creencias = 'creencias';
    case Afiliacion = 'afiliacion';

    /**
     * Válvula de seguridad para una categoría real que el catálogo no
     * anticipó. Nunca se trata como no sensible -eso reintroduciría el mismo
     * hueco que este enum cierra- así que fuerza la excepción de dato
     * sensible mientras se decide si merece su propio caso en un cambio de
     * paquete. Ver la nota de diseño en el reporte de esta tarea.
     */
    case Otro = 'otro';

    public function esSensible(): bool
    {
        return match ($this) {
            self::Identificacion, self::Contacto => false,
            default => true,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Identificacion => 'Identificación',
            self::Contacto => 'Datos de contacto',
            self::Salud => 'Salud',
            self::Discapacidad => 'Discapacidad',
            self::Biometricos => 'Datos biométricos',
            self::PerfilBiologico => 'Perfil biológico',
            self::OrigenEtnico => 'Origen étnico',
            self::Socioeconomico => 'Situación socioeconómica',
            self::VidaSexual => 'Vida sexual',
            self::Creencias => 'Creencias religiosas o filosóficas',
            self::Afiliacion => 'Afiliación gremial o sindical',
            self::Otro => 'Otra categoría no catalogada',
        };
    }
}
