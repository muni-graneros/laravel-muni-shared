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
        // de `InmutabilidadEnBaseDeDatos`, que protege las columnas probatorias
        // y deja pasar las del barrido. El borrado masivo por query builder
        // sigue abierto a propósito: ver el alcance escrito en esa clase.
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
