<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hay finalidades que un adulto puede consentir y un NNA no, aunque firme su
 * representante legal. La bandera vive en el RAT y no en código del sistema
 * adoptante porque es una decisión del responsable del tratamiento, revisable
 * por la autoridad, y el RAT es donde ya viven las demás.
 *
 * `privacidad_finalidades` no tiene `titular_id`, así que esta columna no entra
 * al barrido de anonimización ni a sus guardias de deriva: describe al
 * tratamiento, no a una persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_finalidades', function (Blueprint $table): void {
            // Default true: las finalidades que ya existen vienen tratando
            // menores desde antes de esta columna. Un default false las apagaría
            // retroactivamente y dejaría sin base los consentimientos vigentes
            // de un registro comunal en producción. Prohibir NNA es una decisión
            // que alguien toma finalidad por finalidad, no un efecto secundario
            // de correr una migración.
            $table->boolean('admite_nna')->default(true)->after('es_accesoria');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_finalidades', function (Blueprint $table): void {
            $table->dropColumn('admite_nna');
        });
    }
};
