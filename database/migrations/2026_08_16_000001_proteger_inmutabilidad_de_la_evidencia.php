<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\InmutabilidadEnBaseDeDatos;

/**
 * Lleva la inmutabilidad de la evidencia al motor.
 *
 * Los guardias `updating` de los modelos solo cubren el camino de Eloquent: una
 * escritura masiva del query builder no dispara eventos y alteraba
 * `privacidad_textos` y `privacidad_bitacora` en silencio. Ver
 * `InmutabilidadEnBaseDeDatos` para el alcance exacto de lo que este trigger sí
 * y no cierra.
 */
return new class extends Migration
{
    public function up(): void
    {
        InmutabilidadEnBaseDeDatos::proteger(DB::connection());
    }

    public function down(): void
    {
        InmutabilidadEnBaseDeDatos::desproteger(DB::connection());
    }
};
