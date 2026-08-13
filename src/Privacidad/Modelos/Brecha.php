<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<int, string>|null $categorias_afectadas
 */
class Brecha extends Model
{
    protected $table = 'privacidad_brechas';

    protected $guarded = [];

    protected $casts = [
        'detectada_en' => 'datetime',
        'categorias_afectadas' => 'array',
        'riesgo_alto' => 'boolean',
        'titulares_estimados' => 'integer',
        'notificada_agencia_en' => 'datetime',
        'notificada_titulares_en' => 'datetime',
    ];

    /** @param Builder<Brecha> $query */
    public function scopeSinNotificar(Builder $query): void
    {
        $query->whereNull('notificada_agencia_en');
    }
}
