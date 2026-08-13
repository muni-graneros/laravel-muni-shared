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

    // Esta es la cola de triage: brechas registradas antes de que alguien
    // determinara si el riesgo es alto. No confundir con "riesgo bajo" (que
    // es `riesgo_alto === false`) ni filtrar por sinNotificar() para
    // encontrarlas, porque una brecha sin evaluar también está sin notificar
    // y hay que distinguir ambas colas.
    /** @param Builder<Brecha> $query */
    public function scopeSinEvaluarRiesgo(Builder $query): void
    {
        $query->whereNull('riesgo_alto');
    }
}
