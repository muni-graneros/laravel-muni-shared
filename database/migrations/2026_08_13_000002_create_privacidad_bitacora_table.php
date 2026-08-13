<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia propia del módulo. No se apoya en spatie/laravel-activitylog
 * porque los sistemas del ecosistema no están en la misma versión de ese
 * paquete; donde exista, queda como segunda capa independiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_bitacora', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->string('evento');
            $table->nullableMorphs('titular');
            $table->json('datos')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('ocurrido_en');
            $table->timestamps();

            $table->index(['sistema', 'evento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_bitacora');
    }
};
