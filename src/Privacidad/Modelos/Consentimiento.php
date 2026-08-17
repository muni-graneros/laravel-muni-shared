<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Solicitante;

/**
 * @property string $titular_type único que no se nulea al anonimizar (ver
 *                                Solicitud::$titular_type)
 * @property int|null $titular_id null cuando el titular fue anonimizado
 * @property string|null $titular_ref referencia opaca post-anonimización
 * @property int $finalidad_id
 * @property string|null $vigente_clave guardia de unicidad, no de estado: el
 *                                      vigente lo sigue diciendo solo
 *                                      revocado_en (ver la migración que la
 *                                      agrega)
 * @property Carbon $otorgado_en
 * @property Carbon|null $revocado_en
 * @property MedioDeConsentimiento $medio
 * @property int|null $texto_id fila de TextoInformativo que se mostró; null en
 *                              consentimientos anteriores a esta columna
 * @property string|null $evidencia_path
 * @property string|null $acreditacion_path ruta del documento que acredita la
 *                                          representación; null cuando actúa
 *                                          el propio titular o en filas
 *                                          anteriores a esta columna
 * @property string|null $version_texto columna heredada, ya no se escribe (ver
 *                                      Consentimientos::textoDe())
 * @property Solicitante $otorgado_por
 * @property int|null $user_id
 * @property string|null $ip_hash
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

    /** @return BelongsTo<TextoInformativo, Consentimiento> */
    public function texto(): BelongsTo
    {
        return $this->belongsTo(TextoInformativo::class, 'texto_id');
    }

    /** @param Builder<Consentimiento> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('revocado_en');
    }
}
