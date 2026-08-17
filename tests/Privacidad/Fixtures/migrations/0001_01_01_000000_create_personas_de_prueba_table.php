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
            // La columna que sella el write-through del ecosistema. Está acá
            // porque el fixture tiene que poder ejercitar el camino COMPLETO de
            // SincronizarAlMaestro —incluido el sellado final— y no solo sus
            // guardias: si el control «sin supresión el job sí empuja» no
            // pudiera correr, las pruebas de las guardias no probarían que la
            // guardia es lo que detiene el empuje.
            $table->timestamp('sincronizado_maestro_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas_de_prueba');
    }
};
