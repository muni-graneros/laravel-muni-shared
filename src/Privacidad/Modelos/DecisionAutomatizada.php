<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una decisión que el sistema toma sin intervención humana y cuyo resultado
 * puede afectar significativamente al titular (aceptar o rechazar un
 * beneficio, ordenar una lista de espera). No es el motor que la ejecuta —
 * este módulo no intercepta nada—, es la declaración de que existe, cómo
 * razona y si el titular puede pedir que la revise una persona.
 *
 * @property string $sistema
 * @property string $descripcion
 * @property string $logica
 * @property string $consecuencias
 * @property bool $permite_revision_humana
 * @property bool $activa
 */
class DecisionAutomatizada extends Model
{
    protected $table = 'privacidad_decisiones_automatizadas';

    protected $guarded = [];

    protected $casts = [
        'permite_revision_humana' => 'boolean',
        'activa' => 'boolean',
    ];

    /** @return BelongsTo<Finalidad, DecisionAutomatizada> */
    public function finalidad(): BelongsTo
    {
        return $this->belongsTo(Finalidad::class, 'finalidad_id');
    }

    /** @param  Builder<DecisionAutomatizada>  $query */
    public function scopeDelSistema(Builder $query, string $sistema): void
    {
        $query->where('sistema', $sistema)->where('activa', true);
    }
}
