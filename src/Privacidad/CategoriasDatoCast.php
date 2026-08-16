<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast de `categorias_datos`: JSON de strings en la base de datos, array de
 * `CategoriaDato` en el modelo.
 *
 * La validación ocurre al asignar (`set`), no solo al leer: así una categoría
 * inventada nunca llega a persistirse, y `validarInvariantes()` ya no
 * necesita saber qué strings son válidos porque nunca ve un string suelto.
 *
 * @implements CastsAttributes<array<int, CategoriaDato>|null, iterable<int, CategoriaDato|string>|null>
 */
class CategoriasDatoCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, CategoriaDato>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $crudo = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;

        return array_map($this->resolver(...), $crudo);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $categorias = array_map(
            fn (CategoriaDato|string $categoria): CategoriaDato => $categoria instanceof CategoriaDato
                ? $categoria
                : $this->resolver($categoria),
            $value,
        );

        return json_encode(
            array_map(fn (CategoriaDato $categoria): string => $categoria->value, $categorias),
            JSON_THROW_ON_ERROR,
        );
    }

    private function resolver(string $categoria): CategoriaDato
    {
        return CategoriaDato::tryFrom($categoria) ?? throw new FinalidadInvalida(
            "«{$categoria}» no es una categoría de datos reconocida. Valores válidos: "
            .implode(', ', array_map(fn (CategoriaDato $c): string => $c->value, CategoriaDato::cases())).'.',
        );
    }
}
