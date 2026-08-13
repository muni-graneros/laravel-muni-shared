<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_consentimientos', function (Blueprint $table): void {
            $table->id();
            $table->morphs('titular');
            $table->foreignId('finalidad_id')->constrained('privacidad_finalidades')->cascadeOnDelete();
            $table->timestamp('otorgado_en');
            // Revocar no borra la fila: la evidencia de que hubo consentimiento
            // sigue siendo necesaria para acreditar el tratamiento pasado.
            $table->timestamp('revocado_en')->nullable();
            $table->string('medio');
            $table->string('evidencia_path')->nullable();
            $table->string('version_texto')->nullable();
            $table->string('otorgado_por')->default('titular');
            $table->foreignId('user_id')->nullable();
            $table->string('ip_hash')->nullable();
            $table->timestamps();

            // Sin índice compuesto extra sobre (titular_type, titular_id,
            // finalidad_id): morphs() ya crea el de (titular_type, titular_id),
            // que es su prefijo y sirve a las mismas consultas del servicio.
            // Duplicarlo solo cuesta escrituras.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_consentimientos');
    }
};
