<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Muni\Shared\Privacidad\MedioDeConsentimiento;

/**
 * @property string $sistema
 * @property string $titular_type único que no se nulea al anonimizar (ver
 *                                Solicitud::$titular_type)
 * @property int|null $titular_id null cuando el titular fue anonimizado
 * @property string|null $titular_ref referencia opaca post-anonimización
 * @property int $texto_id
 * @property Carbon $entregado_en
 * @property MedioDeConsentimiento $medio
 * @property int|null $user_id
 * @property string|null $ip_hash
 */
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
