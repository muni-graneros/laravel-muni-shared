<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los textos que se le muestran al titular, versionados.
 *
 * Una fila publicada no se modifica nunca: corregir un texto crea una versión
 * nueva. Si se pudiera editar, no habría forma de acreditar qué leyó realmente
 * la persona que consintió el mes pasado — que es exactamente lo que la ley
 * exige poder probar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_textos', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->string('codigo');
            $table->unsignedInteger('version');
            $table->text('contenido');
            $table->string('hash', 64);
            $table->timestamp('vigente_desde');
            $table->timestamp('vigente_hasta')->nullable();
            $table->timestamps();

            $table->unique(['sistema', 'codigo', 'version']);
            $table->index(['sistema', 'codigo', 'vigente_hasta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_textos');
    }
};
