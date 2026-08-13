<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_brechas', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->timestamp('detectada_en');
            $table->text('descripcion');
            $table->string('naturaleza')->nullable();
            $table->json('categorias_afectadas')->nullable();
            $table->unsignedInteger('titulares_estimados')->nullable();
            // Nullable y sin default: null significa "todavía no se evaluó el
            // riesgo", que es un estado distinto de "se evaluó y no es alto".
            // Una brecha se registra apenas se detecta, antes de que exista
            // triage; defaultear a false archivaría brechas sin evaluar como
            // si ya se hubiera descartado el riesgo, y ese es el error que
            // deja titulares sin notificar. Null nunca debe leerse como "sin
            // riesgo".
            $table->boolean('riesgo_alto')->nullable();
            $table->text('medidas')->nullable();
            // Dos hitos distintos y con destinatarios distintos: la Agencia
            // siempre, los titulares solo cuando el riesgo es alto.
            $table->timestamp('notificada_agencia_en')->nullable();
            $table->timestamp('notificada_titulares_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_brechas');
    }
};
