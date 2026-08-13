<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_solicitudes', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->morphs('titular');
            $table->string('tipo');
            $table->string('estado')->default('recibida');
            $table->timestamp('recibida_en');
            // El incumplimiento típico no es negarse a responder: es no responder
            // a tiempo. Por eso el vencimiento es una columna y no un cálculo.
            $table->timestamp('vence_en');
            $table->timestamp('resuelta_en')->nullable();
            $table->text('detalle');
            $table->text('fundamento_resolucion')->nullable();
            $table->json('verificacion_identidad');
            $table->string('solicitante')->default('titular');
            $table->foreignId('user_registro_id')->nullable();
            $table->foreignId('user_resolucion_id')->nullable();
            $table->string('respuesta_path')->nullable();
            $table->timestamps();

            $table->index(['estado', 'vence_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_solicitudes');
    }
};
