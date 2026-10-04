<?php

declare(strict_types=1);

namespace Muni\Shared\Support;

/**
 * Interpreta lo que devolvió `env()` sin dejar que una variable VACÍA pise el
 * valor por omisión.
 *
 * `env('X', $porOmision)` sólo aplica `$porOmision` cuando la variable está
 * AUSENTE del entorno. Con `X=` (presente y vacía: un compose con el valor sin
 * rellenar, un `.env` copiado del ejemplo, un `-e X=` suelto) devuelve '' —falso
 * para PHP— y el default no se aplica. En `config/privacidad.php` eso dejaba de
 * suspender el tratamiento durante una solicitud ARCOP, ponía los plazos
 * legales en 0 días y volvía eterno el candado de la retención. Acá lo vacío o
 * en blanco vale como ausente y sólo un valor explícito manda. Se usa desde
 * `config/` para sobrevivir a `config:cache`; el candado es
 * `tests/Privacidad/ConfigEnvVaciaTest.php`.
 */
final class VariableDeEntorno
{
    /**
     * Interruptor: sólo `false`, `0`, `off` y `no` apagan; sólo `true`, `1`, `on`
     * y `yes` encienden (sin distinguir mayúsculas). Vacío, en blanco o no
     * reconocido = valor por omisión, que para un interruptor de seguridad es el
     * estado seguro.
     */
    public static function interruptor(mixed $valor, bool $porOmision): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (! is_scalar($valor) || trim((string) $valor) === '') {
            return $porOmision;
        }

        return filter_var(trim((string) $valor), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $porOmision;
    }

    /**
     * Entero: vacío, en blanco o no numérico = valor por omisión. Un `0`
     * explícito sí manda.
     */
    public static function entero(mixed $valor, int $porOmision): int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (! is_scalar($valor) || ! is_numeric(trim((string) $valor))) {
            return $porOmision;
        }

        return (int) trim((string) $valor);
    }

    /**
     * Lista separada por comas, recortada y sin elementos vacíos. Sin ningún
     * elemento (ausente, vacía, en blanco o sólo comas) = lista por omisión,
     * nunca `['']`.
     *
     * @param  list<string>  $porOmision
     * @return list<string>
     */
    public static function lista(mixed $valor, array $porOmision): array
    {
        if (! is_scalar($valor)) {
            return $porOmision;
        }

        $elementos = array_values(array_filter(
            array_map(trim(...), explode(',', (string) $valor)),
            static fn (string $elemento): bool => $elemento !== '',
        ));

        return $elementos === [] ? $porOmision : $elementos;
    }
}
