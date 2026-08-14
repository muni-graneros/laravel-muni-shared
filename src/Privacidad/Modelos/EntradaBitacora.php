<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Muni\Shared\Privacidad\BitacoraInmutable;

/**
 * @property array<string, mixed>|null $datos
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
