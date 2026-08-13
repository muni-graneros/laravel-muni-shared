<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            // Guardia de unicidad, no de estado: el estado "vigente" lo sigue diciendo
            // exclusivamente `revocado_en IS NULL` (ver Consentimiento::scopeVigentes).
            // Esta columna solo existe para que sea la base de datos —y no el orden en
            // que llegan las llamadas de la aplicación— la que impida dos filas
            // vigentes para el mismo (titular, finalidad). NULL no colisiona en un
            // índice único ni en MySQL ni en SQLite, así que las filas revocadas
            // (donde se limpia a null junto con revocado_en) quedan exentas solas.
            $table->string('vigente_clave', 40)->nullable()->after('finalidad_id');
            $table->unique('vigente_clave');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            $table->dropUnique(['vigente_clave']);
            $table->dropColumn('vigente_clave');
        });
    }
};
