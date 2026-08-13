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
            $table->timestamp('tratamiento_iniciado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas_de_prueba');
    }
};
