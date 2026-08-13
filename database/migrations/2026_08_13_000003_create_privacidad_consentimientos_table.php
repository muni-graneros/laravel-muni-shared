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

            $table->index(['titular_type', 'titular_id', 'finalidad_id'], 'privacidad_consentimientos_titular_finalidad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_consentimientos');
    }
};
