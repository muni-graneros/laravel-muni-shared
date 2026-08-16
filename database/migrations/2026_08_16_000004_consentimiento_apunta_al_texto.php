<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `version_texto` era un string suelto que nada obligaba a llenar: no servía
 * para acreditar nada. `texto_id` apunta a la fila exacta, con su hash.
 *
 * Los consentimientos que ya existen quedan con `texto_id` null a propósito:
 * inventarles retroactivamente un texto sería falsear justo la prueba que la
 * ley pide. La columna vieja se conserva por si guardaba algo interpretable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            $table->foreignId('texto_id')->nullable()->after('medio')
                ->constrained('privacidad_textos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('texto_id');
        });
    }
};
