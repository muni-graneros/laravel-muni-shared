<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Solicitante;

/**
 * @property MedioDeConsentimiento $medio
 * @property Solicitante $otorgado_por
 */
class Consentimiento extends Model
{
    protected $table = 'privacidad_consentimientos';

    protected $guarded = [];

    protected $casts = [
        'medio' => MedioDeConsentimiento::class,
        'otorgado_por' => Solicitante::class,
        'otorgado_en' => 'datetime',
        'revocado_en' => 'datetime',
    ];

    /** @return MorphTo<Model, Consentimiento> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Finalidad, Consentimiento> */
    public function finalidad(): BelongsTo
    {
        return $this->belongsTo(Finalidad::class, 'finalidad_id');
    }

    /** @param Builder<Consentimiento> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('revocado_en');
    }
}
