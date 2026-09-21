<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Muni\Shared\Privacidad\Ciclo\PlazoLegal;
use Muni\Shared\Privacidad\CifradoCast;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Solicitante;
use Muni\Shared\Privacidad\TipoDeSolicitud;

/**
 * @property string $sistema
 * @property string $titular_type único que no se nulea al anonimizar: dice de
 *                                qué tipo de sujeto trataba la fila, no quién
 *                                era (ver la migración 2026_08_15_000002)
 * @property int|null $titular_id null cuando el titular fue anonimizado
 * @property string|null $titular_ref referencia opaca post-anonimización, para
 *                                    seguir agrupando el caso sin volver a la
 *                                    persona
 * @property TipoDeSolicitud $tipo
 * @property EstadoDeSolicitud $estado
 * @property Carbon $recibida_en
 * @property Carbon $vence_en
 * @property Carbon|null $resuelta_en
 * @property string $detalle prosa dictada por el ciudadano; cifrada en la
 *                           base (ver CifradoCast)
 * @property string|null $fundamento_resolucion la respuesta escrita al
 *                                              titular; cifrada en la base
 * @property array<string, mixed> $verificacion_identidad cifrada en la base:
 *                                                        `evidencia` guarda el
 *                                                        RUN en claro
 * @property Solicitante $solicitante
 * @property string|null $acreditacion_path ruta del documento que acredita la
 *                                          representación; null cuando actúa
 *                                          el propio titular o en filas
 *                                          anteriores a esta columna
 * @property int|null $user_registro_id
 * @property int|null $user_resolucion_id
 * @property string|null $respuesta_path
 */
class Solicitud extends Model
{
    protected $table = 'privacidad_solicitudes';

    protected $guarded = [];

    protected $casts = [
        'tipo' => TipoDeSolicitud::class,
        'estado' => EstadoDeSolicitud::class,
        'recibida_en' => 'datetime',
        'vence_en' => 'datetime',
        'resuelta_en' => 'datetime',
        // El texto libre va cifrado en reposo: `detalle` es lo que dicta el
        // ciudadano (su RUT, su dirección, en discapacidad un diagnóstico),
        // `verificacion_identidad.evidencia` es el RUN con que se acreditó y
        // `fundamento_resolucion` es la respuesta al titular. Las tres tablas
        // las comparten los ocho sistemas.
        //
        // Ojo con los `update()` masivos (`Solicitud::query()->update()`): no
        // pasan por los casts. En este modelo hoy no hay ninguno; si aparece,
        // va con `CifradoCast::cifrar()` como en `Bloqueos`.
        'detalle' => CifradoCast::class,
        'fundamento_resolucion' => CifradoCast::class,
        'verificacion_identidad' => CifradoCast::class.':array',
        'solicitante' => Solicitante::class,
    ];

    /** @return MorphTo<Model, Solicitud> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    public function diasRestantes(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->vence_en->startOfDay(), false);
    }

    /** @param Builder<Solicitud> $query */
    public function scopePendientes(Builder $query): void
    {
        $query->whereIn('estado', [EstadoDeSolicitud::Recibida->value, EstadoDeSolicitud::EnTramite->value]);
    }

    /** @param Builder<Solicitud> $query */
    public function scopePorVencer(Builder $query, int $dias = PlazoLegal::DIAS_POR_VENCER): void
    {
        $query->pendientes()
            ->whereBetween('vence_en', [now(), now()->addDays($dias)]);
    }

    /** @param Builder<Solicitud> $query */
    public function scopeVencidas(Builder $query): void
    {
        $query->pendientes()->where('vence_en', '<', now());
    }
}
