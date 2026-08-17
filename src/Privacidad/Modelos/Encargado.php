<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Un tercero que trata datos por cuenta del responsable, o al que se le
 * comunican: quien recibe un dato desde alguna finalidad de este sistema.
 *
 * No es un titular. No lleva `titular_id` ni pasa por
 * `Bitacora::desvincular()` —ver el docblock de la migración que crea esta
 * tabla para el porqué exacto, incluido el de `contrato_path`—: es un
 * catálogo de organizaciones, y sus filas no se anonimizan cuando se
 * anonimiza a un vecino.
 *
 * @property string $sistema
 * @property string $nombre
 * @property string $rol
 * @property string|null $contrato_path
 * @property Carbon|null $contrato_firmado_en
 * @property Carbon|null $contrato_vence_en
 * @property string|null $pais
 * @property string|null $medidas
 * @property bool $activo
 */
class Encargado extends Model
{
    protected $table = 'privacidad_encargados';

    protected $guarded = [];

    protected $casts = [
        'contrato_firmado_en' => 'date',
        'contrato_vence_en' => 'date',
        'activo' => 'boolean',
    ];

    /** @return BelongsToMany<Finalidad> */
    public function finalidades(): BelongsToMany
    {
        return $this->belongsToMany(Finalidad::class, 'privacidad_encargado_finalidad', 'encargado_id', 'finalidad_id');
    }

    /**
     * Sin contrato firmado, o con uno que ya venció.
     *
     * Un `contrato_vence_en` nulo se toma como vigencia indefinida, no como
     * vencido: hay convenios municipales sin plazo de término, y marcarlos en
     * rojo en el RAT sería ruido que nadie puede resolver.
     *
     * Solo mira encargados activos: uno dado de baja ya no trata datos de
     * este sistema, así que su contrato vencido no es una alerta operativa,
     * es historia.
     *
     * @param  Builder<Encargado>  $query
     */
    public function scopeSinContratoVigente(Builder $query): void
    {
        $query->where('activo', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('contrato_firmado_en')
                ->orWhere(fn (Builder $q) => $q
                    ->whereNotNull('contrato_vence_en')
                    ->where('contrato_vence_en', '<', now()->toDateString())));
    }
}
