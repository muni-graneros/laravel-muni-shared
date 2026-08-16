<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Bloqueo extends Model
{
    protected $table = 'privacidad_bloqueos';

    protected $guarded = [];

    protected $casts = [
        'desde' => 'datetime',
        'levantado_en' => 'datetime',
    ];

    /** @return MorphTo<Model, Bloqueo> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Finalidad, Bloqueo> */
    public function finalidad(): BelongsTo
    {
        return $this->belongsTo(Finalidad::class, 'finalidad_id');
    }

    /** @return BelongsTo<Solicitud, Bloqueo> */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(Solicitud::class, 'solicitud_id');
    }

    /** @param Builder<Bloqueo> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('levantado_en');
    }
}
