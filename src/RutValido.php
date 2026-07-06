<?php

namespace Muni\Shared;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación de RUT/RUN chileno.
 *
 * La lógica (módulo 11, normalización, formato) vive en App\Helpers\RutHelper,
 * fuente única de verdad. Esta clase solo la expone como ValidationRule.
 */
class RutValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! RutHelper::validate($value)) {
            $fail('El campo :attribute no es un RUT/RUN válido.');
        }
    }

    /**
     * Formatea un RUT al estándar chileno (ej. "12.345.678-9").
     *
     * Se conserva por compatibilidad con el código existente; delega en
     * RutHelper::format(). En código nuevo, usa RutHelper directamente.
     */
    public static function formatear(string $rut): string
    {
        return RutHelper::format($rut);
    }
}
