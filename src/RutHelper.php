<?php

namespace Muni\Shared;

/**
 * Utilidades de RUT/RUN chileno (dígito verificador con módulo 11).
 *
 * Fuente única de verdad para normalizar, formatear, limpiar y validar RUTs.
 * La regla de validación `App\Rules\RutValido` delega aquí para no duplicar el
 * algoritmo. Úsalo en el resolver y en las validaciones de formularios.
 */
class RutHelper
{
    /** Solo números + dígito verificador (K mayúscula). Ej: "14.523.681-9" → "145236819". */
    public static function clean(string $rut): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    }

    /** Formato canónico con guión. Ej: cualquier formato → "14523681-9". */
    public static function normalize(string $rut): string
    {
        $clean = self::clean($rut);

        if (strlen($clean) < 2) {
            return $clean;
        }

        return substr($clean, 0, -1).'-'.substr($clean, -1);
    }

    /** Formato chileno con puntos y guión. Ej: cualquier formato → "14.523.681-9". */
    public static function format(string $rut): string
    {
        $clean = self::clean($rut);

        if (strlen($clean) < 2) {
            return $clean;
        }

        $cuerpo = substr($clean, 0, -1);
        $dv = substr($clean, -1);

        return number_format((int) $cuerpo, 0, '', '.').'-'.$dv;
    }

    /** Valida formato y dígito verificador (módulo 11). */
    public static function validate(string $rut): bool
    {
        $clean = self::clean($rut);

        if (strlen($clean) < 7 || strlen($clean) > 9) {
            return false;
        }

        $cuerpo = substr($clean, 0, -1);
        $dv = substr($clean, -1);

        if (! ctype_digit($cuerpo)) {
            return false;
        }

        return self::calcularDv($cuerpo) === $dv;
    }

    /** Calcula el dígito verificador de un cuerpo numérico (módulo 11). */
    public static function calcularDv(string $cuerpo): string
    {
        $suma = 0;
        $multiplo = 2;

        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += ((int) $cuerpo[$i]) * $multiplo;
            $multiplo = $multiplo === 7 ? 2 : $multiplo + 1;
        }

        $diferencia = 11 - ($suma % 11);

        return match ($diferencia) {
            11 => '0',
            10 => 'K',
            default => (string) $diferencia,
        };
    }
}
