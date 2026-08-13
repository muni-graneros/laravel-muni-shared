<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\FinalidadInvalida;

/**
 * @property BaseLicitud $base_licitud
 * @property array<int, string>|null $categorias_datos
 * @property array<int, string>|null $destinatarios
 */
class Finalidad extends Model
{
    protected $table = 'privacidad_finalidades';

    protected $guarded = [];

    protected $casts = [
        'base_licitud' => BaseLicitud::class,
        'es_accesoria' => 'boolean',
        'activa' => 'boolean',
        'plazo_retencion_meses' => 'integer',
        'categorias_datos' => 'array',
        'destinatarios' => 'array',
    ];

    protected static function booted(): void
    {
        // Las invariantes se validan al guardar y no en el formulario, porque el
        // RAT también se puebla por seeders y por consola.
        static::saving(fn (Finalidad $finalidad) => $finalidad->validarInvariantes());
    }

    public function validarInvariantes(): void
    {
        if ($this->es_accesoria && $this->base_licitud !== BaseLicitud::Consentimiento) {
            throw new FinalidadInvalida(
                "La finalidad accesoria «{$this->codigo}» debe fundarse en el consentimiento: "
                .'si es separable del servicio, el titular tiene que poder negarse.',
            );
        }

        if ($this->base_licitud->exigeNormaHabilitante() && blank($this->norma_habilitante)) {
            throw new FinalidadInvalida(
                "La finalidad «{$this->codigo}» se funda en la ley pero no dice en cuál. "
                .'Indicar la norma habilitante.',
            );
        }
    }

    /** @param Builder<Finalidad> $query */
    public function scopeAccesorias(Builder $query): void
    {
        $query->where('es_accesoria', true);
    }

    /** @param Builder<Finalidad> $query */
    public function scopeDelSistema(Builder $query, string $sistema): void
    {
        $query->where('sistema', $sistema);
    }
}
