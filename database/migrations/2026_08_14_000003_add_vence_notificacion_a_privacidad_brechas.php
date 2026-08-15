<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_brechas', function (Blueprint $table): void {
            // Columna y no cálculo: el plazo configurado puede cambiar, y el que
            // corre para una brecha es el que regía cuando se detectó.
            $table->timestamp('vence_notificacion_agencia_en')->nullable()->after('detectada_en');
            $table->index(['notificada_agencia_en', 'vence_notificacion_agencia_en'], 'privacidad_brechas_plazo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_brechas', function (Blueprint $table): void {
            $table->dropIndex('privacidad_brechas_plazo_idx');
            $table->dropColumn('vence_notificacion_agencia_en');
        });
    }
};
