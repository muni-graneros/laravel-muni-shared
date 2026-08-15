<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Muni\Shared\Privacidad\MedioDeConsentimiento;

class InformacionEntregada extends Model
{
    protected $table = 'privacidad_informaciones';

    protected $guarded = [];

    protected $casts = [
        'medio' => MedioDeConsentimiento::class,
        'entregado_en' => 'datetime',
    ];

    /** @return MorphTo<Model, InformacionEntregada> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<TextoInformativo, InformacionEntregada> */
    public function texto(): BelongsTo
    {
        return $this->belongsTo(TextoInformativo::class, 'texto_id');
    }
}
