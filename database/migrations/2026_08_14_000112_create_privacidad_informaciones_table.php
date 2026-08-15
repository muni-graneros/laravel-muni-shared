<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La prueba de que se informó al titular, y de qué versión del texto vio.
 *
 * Es la obligación que más veces se ejerce —ocurre en cada inscripción, no una
 * vez al año como el RAT— y la que no tenía ningún soporte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_informaciones', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->morphs('titular');
            $table->foreignId('texto_id')->constrained('privacidad_textos')->restrictOnDelete();
            $table->timestamp('entregado_en');
            $table->string('medio');
            $table->foreignId('user_id')->nullable();
            $table->string('ip_hash')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_informaciones');
    }
};
