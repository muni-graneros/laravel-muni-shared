<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\TextoInmutable;

class TextoInformativo extends Model
{
    protected $table = 'privacidad_textos';

    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
        'vigente_desde' => 'datetime',
        'vigente_hasta' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Inmutable en el contenido. Cerrar la vigencia SÍ es una transición
        // legítima —publicar la versión siguiente cierra la anterior— y por eso
        // Textos::publicar() la hace por query builder, que no dispara eventos.
        //
        // Este guardia cubre SOLO el camino del modelo. Las escrituras masivas
        // del query builder no disparan eventos de Eloquent, así que
        // `TextoInformativo::query()->update(['contenido' => …])` pasaba por al
        // lado sin excepción. Eso lo ataja el trigger de
        // `InmutabilidadEnBaseDeDatos`, en el motor; acá queda la excepción de
        // dominio para el uso normal.
        //
        // Lo que sigue abierto a propósito es el borrado por query builder, y
        // hay que leerlo como lo que es: quien tiene DELETE sobre esta tabla
        // puede reescribir un texto publicado EN SU LUGAR con un `REPLACE INTO`
        // de la misma `id` —borrado más inserción, sin trigger BEFORE UPDATE de
        // por medio—, con lo que el consentimiento que apunta a esa fila pasa a
        // acreditar un texto que la persona nunca leyó. La inmutabilidad de esta
        // tabla la sostienen el trigger MÁS los permisos del motor: ver el
        // alcance en esa clase y los GRANT del spec de pendientes.
        static::updating(function (): never {
            throw new TextoInmutable(
                'Un texto publicado no se modifica: los consentimientos que lo apuntan '
                .'dejarían de acreditar qué leyó el titular. Publicar una versión nueva.',
            );
        });

        static::deleting(function (): never {
            throw new TextoInmutable('Un texto publicado no se borra: es la prueba de lo que se informó.');
        });
    }

    /** @param Builder<TextoInformativo> $query */
    public function scopeDelSistema(Builder $query, string $sistema): void
    {
        $query->where('sistema', $sistema);
    }

    /** @param Builder<TextoInformativo> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('vigente_hasta');
    }
}
