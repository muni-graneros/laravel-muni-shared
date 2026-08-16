<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\InmutabilidadEnBaseDeDatos;

/**
 * Reinstala el guardia con `privacidad_textos.vigente_hasta` de una sola vez.
 *
 * La columna quedaba libre porque `Textos::publicar()` la escribe al cerrar la
 * versión anterior. Libre del todo, sin embargo, deja reescribir la línea de
 * tiempo de qué aviso estaba vigente cuándo, y eso es parte de lo que la
 * evidencia acredita: el consentimiento guarda el id del texto, pero la fecha es
 * la que dice si ese texto era el vigente el día que la persona lo aceptó. Se
 * permite estrenarla (NULL → fecha) y nada más.
 *
 * Va junto con el `lockForUpdate()` de `publicarVersionSiguiente()`: sin él, dos
 * publicadores simultáneos intentan cerrar la misma vigencia y el segundo se
 * come el rechazo del motor. Ver el docblock de `COLUMNAS_UNA_SOLA_VEZ`.
 */
return new class extends Migration
{
    public function up(): void
    {
        InmutabilidadEnBaseDeDatos::proteger(DB::connection());
    }

    /** Reinstala el mismo guardia; ver el `down()` de 2026_08_16_000002. */
    public function down(): void
    {
        InmutabilidadEnBaseDeDatos::proteger(DB::connection());
    }
};
