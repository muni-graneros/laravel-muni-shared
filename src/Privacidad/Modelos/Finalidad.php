<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\CategoriaDato;
use Muni\Shared\Privacidad\CategoriasDatoCast;
use Muni\Shared\Privacidad\ExcepcionDatoSensible;
use Muni\Shared\Privacidad\FinalidadInvalida;

/**
 * @property string $sistema
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property BaseLicitud $base_licitud
 * @property string|null $norma_habilitante
 * @property bool $es_accesoria
 * @property int|null $plazo_retencion_meses
 * @property bool $admite_nna
 * @property ExcepcionDatoSensible|null $excepcion_dato_sensible
 * @property array<int, CategoriaDato>|null $categorias_datos
 * @property array<int, string>|null $destinatarios
 * @property bool $activa
 */
class Finalidad extends Model
{
    protected $table = 'privacidad_finalidades';

    protected $guarded = [];

    /**
     * El default de la columna no basta: Eloquent no relee la fila después de
     * insertarla, así que un `Finalidad::create()` que no mencione `admite_nna`
     * deja el atributo en null en memoria, y null es falsy. El régimen de NNA
     * leería "esta finalidad no admite menores" en una finalidad recién creada
     * que sí los admite. El default vive en los dos lados a propósito: en la
     * migración para las filas que ya existen, acá para la instancia en memoria.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'admite_nna' => true,
    ];

    protected $casts = [
        'base_licitud' => BaseLicitud::class,
        'excepcion_dato_sensible' => ExcepcionDatoSensible::class,
        'es_accesoria' => 'boolean',
        'admite_nna' => 'boolean',
        'activa' => 'boolean',
        'plazo_retencion_meses' => 'integer',
        'categorias_datos' => CategoriasDatoCast::class,
        'destinatarios' => 'array',
    ];

    protected static function booted(): void
    {
        // Las invariantes se validan al guardar y no en el formulario, porque el
        // RAT también se puebla por seeders y por consola. Ojo: viajan sobre eventos
        // del modelo, así que una actualización masiva (::where(...)->update([...]))
        // las esquiva; no son una garantía a prueba de escrituras en bloque.
        static::saving(fn (Finalidad $finalidad) => $finalidad->validarInvariantes());
    }

    public function validarInvariantes(): void
    {
        if (! $this->base_licitud instanceof BaseLicitud) {
            throw new FinalidadInvalida(
                "La finalidad «{$this->codigo}» no tiene base de licitud: una finalidad sin base "
                .'legal no es un tratamiento lícito, es una declaración sin fundamento.',
            );
        }

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

        // La sensibilidad se deriva del enum, no de una lista paralela: no hay
        // forma de que `categorias_datos` contenga un string no reconocido
        // acá, porque el cast ya lo rechazó al asignarlo.
        $sensibles = array_values(array_filter(
            $this->categorias_datos ?? [],
            fn (CategoriaDato $categoria): bool => $categoria->esSensible(),
        ));

        if ($sensibles !== [] && $this->excepcion_dato_sensible === null) {
            $nombres = implode(', ', array_map(fn (CategoriaDato $c): string => $c->value, $sensibles));

            throw new FinalidadInvalida(
                "La finalidad «{$this->codigo}» trata datos sensibles ({$nombres}) "
                .'pero no declara la causal que lo habilita. La base de licitud general no basta: '
                .'la ley prohíbe tratarlos salvo causales tasadas.',
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

    /**
     * Los encargados y destinatarios a los que esta finalidad comunica datos.
     *
     * La contraparte con contrato y control de vigencia de lo que
     * `destinatarios` describía como una simple lista de nombres.
     *
     * @return BelongsToMany<Encargado>
     */
    public function encargados(): BelongsToMany
    {
        return $this->belongsToMany(Encargado::class, 'privacidad_encargado_finalidad', 'finalidad_id', 'encargado_id');
    }
}
