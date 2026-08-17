<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $sistema
 * @property string|null $titular_type nullable desde el origen, a diferencia
 *                                     de Solicitud (ver el docblock de la
 *                                     migración que crea esta tabla)
 * @property int|null $titular_id
 * @property string|null $titular_ref
 * @property int|null $finalidad_id null = todas las finalidades
 * @property int|null $solicitud_id
 * @property string $motivo
 * @property Carbon $desde
 * @property Carbon|null $levantado_en
 * @property int|null $user_id
 */
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

    /**
     * Los bloqueos de UN sistema del ecosistema.
     *
     * Existe por lo mismo que el de `Finalidad`: esta tabla es compartida por
     * los ocho sistemas y la columna estaba escrita pero no consultada. Ver el
     * docblock de `Bloqueos` para por qué el alcance del bloqueo es el sistema
     * en que se presentó la solicitud y no el ecosistema entero.
     *
     * @param  Builder<Bloqueo>  $query
     */
    public function scopeDelSistema(Builder $query, string $sistema): void
    {
        $query->where('sistema', $sistema);
    }
}
