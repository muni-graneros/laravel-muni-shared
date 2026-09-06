<?php

declare(strict_types=1);

namespace Muni\Shared\Seguridad;

use Laravel\Octane\Octane;

/**
 * Bajo Octane, los permisos de spatie tienen que soltarse entre peticiones.
 *
 * `spatie/laravel-permission` guarda su registro de permisos en memoria dentro
 * del `PermissionRegistrar`. En PHP clásico esa memoria muere con la petición;
 * bajo Octane el worker sigue vivo, así que el registro sobrevive: a quien se le
 * revoca un rol se lo sigue reconociendo hasta que el worker recicle. La clave
 * `permission.register_octane_reset_listener` es lo que engancha el listener que
 * lo suelta, y **el valor por omisión de spatie es `false`**.
 *
 * Los ocho sistemas del ecosistema despliegan sobre FrankenPHP/Octane y los
 * siete que publicaron `config/permission.php` lo tienen en `true`. Ese es —
 * medido, valor por valor— el ÚNICO apartamiento municipal respecto del config
 * que publica spatie: todo lo demás de esos 206 (o 52) renglones son sus
 * defaults y sus comentarios.
 *
 * Por eso el archivo no se mueve al paquete y sí se mueve la regla. Traer el
 * config entero obligaría a seguir acá el esquema de spatie —las copias de cinco
 * sistemas ya se quedaron sin `models.team` y `models.default_model`, agregadas
 * en una versión posterior— para custodiar un solo booleano.
 */
final class PermisosBajoOctane
{
    /**
     * Lo que hay que arreglar, en castellano y listo para un mensaje de fallo.
     *
     * Lista vacía = nada que objetar. `$conOctane` se detecta solo (¿está
     * instalado `laravel/octane`?) y se puede forzar desde una prueba.
     *
     * @return list<string>
     */
    public static function problemas(?bool $conOctane = null): array
    {
        $conOctane ??= self::hayOctane();

        // Sin Octane el registro muere con la petición y no hay nada que soltar.
        // Sin spatie/permission instalado tampoco hay nada que mirar: el config
        // no existe y exigirlo rompería a atencionvecino, que no lo usa.
        if (! $conOctane || config('permission') === null) {
            return [];
        }

        if (config('permission.register_octane_reset_listener') === true) {
            return [];
        }

        return [
            'config/permission.php tiene «register_octane_reset_listener» apagado y el sistema '
            .'corre sobre Octane: el registro de permisos de spatie sobrevive entre peticiones '
            .'del mismo worker, así que a quien se le revoca un rol se lo sigue reconociendo '
            .'hasta que el worker recicle. Ponerlo en true (el valor por omisión de spatie es false).',
        ];
    }

    private static function hayOctane(): bool
    {
        return class_exists(Octane::class);
    }
}
