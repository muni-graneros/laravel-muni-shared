<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Muni\Shared\Privacidad\BitacoraInmutable;

/**
 * @property array<string, mixed>|null $datos
 * @property string|null $titular_ref
 */
class EntradaBitacora extends Model
{
    protected $table = 'privacidad_bitacora';

    protected $guarded = [];

    protected $casts = [
        'datos' => 'array',
        'ocurrido_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Append-only. La única mutación permitida es cortar el vínculo con el
        // titular al anonimizarlo, y esa va por query builder desde
        // Bitacora::desvincular(), que no dispara eventos de modelo y deja su
        // propia entrada registrando que ocurrió.
        //
        // Que no dispare eventos es justamente lo que hacía porosa esta
        // guardia: cualquiera podía entrar por la misma puerta y alterar
        // `evento` o `datos` en silencio. El motor lo rechaza desde el trigger
        // de `InmutabilidadEnBaseDeDatos`, que congela las columnas probatorias
        // y le pone dirección a las del barrido —los punteros al titular y al
        // usuario solo pueden soltarse, nunca reasignarse—.
        //
        // Lo que sigue abierto a propósito es el borrado por query builder, y
        // conviene decirlo por su nombre: quien tiene DELETE sobre esta tabla no
        // solo puede borrar evidencia, puede REESCRIBIRLA en su lugar con un
        // `REPLACE INTO` de la misma `id`, que es un borrado más una inserción y
        // por lo tanto no dispara ningún trigger BEFORE UPDATE. O sea que la
        // inmutabilidad de esta tabla no la sostiene solo el trigger: la sostiene
        // el trigger MÁS los permisos del motor. Ver el alcance escrito en esa
        // clase y los GRANT del spec de pendientes.
        static::updating(function (): never {
            throw new BitacoraInmutable(
                'Una entrada de bitácora no se modifica: es el registro de evidencia del tratamiento.',
            );
        });

        static::deleting(function (): never {
            throw new BitacoraInmutable(
                'Una entrada de bitácora no se borra. Para desvincularla de un titular anonimizado, '
                .'usar Bitacora::desvincular().',
            );
        });
    }

    /** @return MorphTo<Model, EntradaBitacora> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }
}
