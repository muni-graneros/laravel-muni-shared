<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $sistema
 * @property Carbon $detectada_en
 * @property Carbon|null $vence_notificacion_agencia_en
 * @property string $descripcion
 * @property string|null $naturaleza
 * @property array<int, string>|null $categorias_afectadas
 * @property int|null $titulares_estimados
 * @property bool|null $riesgo_alto null = todavía sin evaluar, no "riesgo
 *                                  descartado" (ver scopeSinEvaluarRiesgo())
 * @property string|null $medidas
 * @property Carbon|null $notificada_agencia_en
 * @property Carbon|null $notificada_titulares_en
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
        'vence_notificacion_agencia_en' => 'datetime',
    ];

    /** @param Builder<Brecha> $query */
    public function scopeSinNotificar(Builder $query): void
    {
        $query->whereNull('notificada_agencia_en');
    }

    /** @param Builder<Brecha> $query */
    public function scopePorVencer(Builder $query, int $dias = 1): void
    {
        $query->sinNotificar()
            ->whereNotNull('vence_notificacion_agencia_en')
            ->whereBetween('vence_notificacion_agencia_en', [now(), now()->addDays($dias)]);
    }

    /** @param Builder<Brecha> $query */
    public function scopeVencidas(Builder $query): void
    {
        $query->sinNotificar()
            ->whereNotNull('vence_notificacion_agencia_en')
            ->where('vence_notificacion_agencia_en', '<', now());
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
