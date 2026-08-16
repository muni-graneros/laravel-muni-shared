<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\InmutabilidadEnBaseDeDatos;

/**
 * Reinstala el guardia con la condición direccional sobre autoría y titular.
 *
 * El trigger de `2026_08_16_000001` congelaba QUÉ pasó y dejaba reescribible
 * QUIÉN lo hizo: con el guardia puesto,
 * `update privacidad_bitacora set user_id = 999, titular_id = 4242, titular_ref = '…'`
 * pasaba sin excepción en SQLite y en MariaDB (comprobado corriéndolo, no
 * leyendo el SQL). Esas columnas tienen que seguir siendo escribibles porque
 * `Bitacora::desvincular()` las barre al anonimizar, así que no se congelan: se
 * les pone dirección. Ver `InmutabilidadEnBaseDeDatos::COLUMNAS_SOLO_HACIA_NULL`
 * y `::COLUMNAS_UNA_SOLA_VEZ`.
 *
 * Va en una migración aparte y no editando la anterior porque los adoptantes ya
 * corrieron aquella: para ellos esto es un cambio nuevo, no una corrección de
 * historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        // proteger() borra el trigger anterior antes de crear el nuevo, así que
        // sirve igual como instalación y como reemplazo.
        InmutabilidadEnBaseDeDatos::proteger(DB::connection());
    }

    /**
     * Vuelve a instalar el mismo guardia, a propósito.
     *
     * El SQL vive en la clase y no en la migración, así que no hay forma de
     * reponer la versión floja del trigger sin duplicarla acá — y reponerla
     * sería restaurar el agujero. Bajar esta migración deja el guardia
     * endurecido; para quedarse sin guardia hay que bajar también la anterior,
     * que es la que lo instaló.
     */
    public function down(): void
    {
        InmutabilidadEnBaseDeDatos::proteger(DB::connection());
    }
};
