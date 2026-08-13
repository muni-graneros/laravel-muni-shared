<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    /** @return MorphTo<Model, EntradaBitacora> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }
}
