<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referencia opaca al titular, para después de anonimizarlo.
 *
 * Es un valor ALEATORIO generado al desvincular, no un hash del identificador:
 * un hash con la lista de ids se revierte por fuerza bruta, y entonces la
 * anonimización sería decorativa. Permite agrupar las entradas de un mismo caso
 * sin ninguna forma de volver a la persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_bitacora', function (Blueprint $table): void {
            $table->string('titular_ref', 26)->nullable()->after('titular_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_bitacora', function (Blueprint $table): void {
            $table->dropColumn('titular_ref');
        });
    }
};
