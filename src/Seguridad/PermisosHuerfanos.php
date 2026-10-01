<?php

declare(strict_types=1);

namespace Muni\Shared\Seguridad;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\Permission as PermissionContrato;

/**
 * Un permiso que NINGÚN rol recibe es un permiso muerto: la política o el widget
 * que lo exige queda inalcanzable para todos salvo para quien tenga `Gate::before`
 * (super_admin), y nadie se entera porque «el super admin sí lo ve». Pasó con el
 * widget ARCOP de «solicitudes por vencer»: Shield generó el permiso y ningún rol
 * del seeder lo recibía, así que supervisor y jefatura nunca vieron el widget.
 *
 * Mira la BASE ACTUAL: la prueba que lo usa tiene que haber corrido antes el
 * seeder de roles (el mismo que corre producción). Requiere
 * `spatie/laravel-permission` (es `suggest` del paquete).
 *
 * Una excepción declarada que ya no hace falta (el permiso no existe, o ya lo
 * recibe algún rol) también es un problema: una lista de excepciones que no se
 * poda termina escondiendo el próximo permiso huérfano real.
 */
final class PermisosHuerfanos
{
    /**
     * @param  list<string>  $excepciones  nombres de permiso que a propósito no reciben los roles
     *                                     (p. ej. los que sólo usa `super_admin` vía `Gate::before`)
     * @return list<string> lo que hay que arreglar; vacío = nada que objetar
     */
    public static function problemas(array $excepciones = [], ?string $guard = null): array
    {
        $claseDePermiso = app(PermissionContrato::class)::class;

        /** @var class-string<Model> $claseDePermiso */
        $consulta = $claseDePermiso::query();

        if ($guard !== null) {
            $consulta->where('guard_name', $guard);
        }

        $nombres = $consulta->pluck('name')->all();

        if ($nombres === []) {
            return ['No hay ningún permiso en la base: ¿corriste el seeder de roles y permisos antes de la aserción?'];
        }

        $huerfanos = (clone $consulta)->whereDoesntHave('roles')->pluck('name')->all();

        $problemas = [];

        foreach (array_diff($huerfanos, $excepciones) as $nombre) {
            $problemas[] = "El permiso «{$nombre}» no lo recibe ningún rol: es inalcanzable salvo para super_admin. "
                .'Asígnalo en el seeder de roles o, si es a propósito, agrégalo a las excepciones.';
        }

        foreach ($excepciones as $nombre) {
            if (! in_array($nombre, $nombres, true)) {
                $problemas[] = "La excepción «{$nombre}» ya no es un permiso existente: quítala de la lista.";
            } elseif (! in_array($nombre, $huerfanos, true)) {
                $problemas[] = "La excepción «{$nombre}» ya la recibe algún rol: quítala de la lista.";
            }
        }

        return $problemas;
    }
}
