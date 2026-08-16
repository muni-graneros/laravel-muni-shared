<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspensión del tratamiento mientras hay una disputa abierta.
 *
 * Sin esto, una rectificación pendiente no impide que el dato incorrecto se
 * siga usando: el sistema sigue operando con lo que el titular ya dijo que
 * está mal.
 *
 * `titular_id` nace nullable y con `titular_ref` desde el principio —a
 * diferencia de `privacidad_solicitudes`, que los ganó en una migración
 * aparte (2026_08_15_000002) porque ya existía con la columna NOT NULL—: esta
 * tabla se crea después de que el barrido de `Bitacora::desvincular()`
 * aprendió a dejar huérfanas las tablas del módulo, así que no hace falta el
 * paso intermedio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_bloqueos', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->nullableMorphs('titular');
            $table->string('titular_ref', 32)->nullable()->index();
            // Null = todas las finalidades.
            $table->foreignId('finalidad_id')->nullable()->constrained('privacidad_finalidades')->cascadeOnDelete();
            $table->foreignId('solicitud_id')->nullable()->constrained('privacidad_solicitudes')->nullOnDelete();
            $table->text('motivo');
            $table->timestamp('desde');
            // Levantar no borra: la suspensión pasada es un hecho acreditable.
            $table->timestamp('levantado_en')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index(['titular_type', 'titular_id', 'levantado_en'], 'privacidad_bloqueos_titular_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_bloqueos');
    }
};
