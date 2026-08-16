<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas_de_prueba', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('documento')->nullable();
            $table->string('diagnostico')->nullable();
            // Nullable a propósito: el fixture tiene que poder representar el
            // caso "edad no acreditada", que es el estado que el módulo rechaza.
            $table->date('fecha_nacimiento')->nullable();
            $table->timestamp('tratamiento_iniciado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas_de_prueba');
    }
};
