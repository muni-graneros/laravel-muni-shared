<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La causal que habilita tratar datos sensibles.
 *
 * Nullable en el esquema porque solo aplica a las finalidades que declaran
 * alguna categoría sensible; la obligatoriedad la impone la invariante del
 * modelo, que es quien sabe qué categorías declaró cada finalidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_finalidades', function (Blueprint $table): void {
            $table->string('excepcion_dato_sensible')->nullable()->after('norma_habilitante');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_finalidades', function (Blueprint $table): void {
            $table->dropColumn('excepcion_dato_sensible');
        });
    }
};
