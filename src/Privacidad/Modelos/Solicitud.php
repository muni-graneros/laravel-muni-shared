<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

/**
 * @property TipoDeSolicitud $tipo
 * @property EstadoDeSolicitud $estado
 * @property Carbon $vence_en
 * @property array<string, mixed> $verificacion_identidad
 */
class Solicitud extends Model
{
    protected $table = 'privacidad_solicitudes';

    protected $guarded = [];

    protected $casts = [
        'tipo' => TipoDeSolicitud::class,
        'estado' => EstadoDeSolicitud::class,
        'recibida_en' => 'datetime',
        'vence_en' => 'datetime',
        'resuelta_en' => 'datetime',
        'verificacion_identidad' => 'array',
    ];

    /** @return MorphTo<Model, Solicitud> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    public function diasRestantes(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->vence_en->startOfDay(), false);
    }

    /** @param Builder<Solicitud> $query */
    public function scopePendientes(Builder $query): void
    {
        $query->whereIn('estado', [EstadoDeSolicitud::Recibida->value, EstadoDeSolicitud::EnTramite->value]);
    }

    /** @param Builder<Solicitud> $query */
    public function scopePorVencer(Builder $query, int $dias = 5): void
    {
        $query->pendientes()
            ->whereBetween('vence_en', [now(), now()->addDays($dias)]);
    }

    /** @param Builder<Solicitud> $query */
    public function scopeVencidas(Builder $query): void
    {
        $query->pendientes()->where('vence_en', '<', now());
    }
}
