<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de actividades de tratamiento (RAT) que exige la Ley 21.719,
 * como datos y no como documento: un Word se desactualiza en silencio, una
 * tabla que alimenta la purga y el panel no puede desactualizarse sin que se note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_finalidades', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->string('codigo');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('base_licitud');
            $table->string('norma_habilitante')->nullable();
            $table->boolean('es_accesoria')->default(false);
            $table->unsignedInteger('plazo_retencion_meses')->nullable();
            $table->json('categorias_datos')->nullable();
            $table->json('destinatarios')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['sistema', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_finalidades');
    }
};
