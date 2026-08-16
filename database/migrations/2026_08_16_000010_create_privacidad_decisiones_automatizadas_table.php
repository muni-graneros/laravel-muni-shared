<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro declarativo, no un motor.
 *
 * La ley da derecho a no ser objeto de decisiones automatizadas con efectos
 * jurídicos o significativos, y a pedir revisión humana. Su valor acá es
 * obligar a la pregunta y poder responderla ante una fiscalización —incluida
 * la respuesta «ninguna», que es válida cuando es cierta—.
 *
 * Sin `titular_id` ni `titular_type`, a propósito y no por omisión: una fila
 * de esta tabla describe el COMPORTAMIENTO de un sistema («este sistema
 * prioriza así»), no un hecho sobre una persona. `Bitacora::desvincular()` y
 * las guardias de deriva de `AnonimizacionDelGrafoTest` (que descubren las
 * tablas del módulo leyendo el esquema, buscando `titular_id`) la dejan fuera
 * del barrido por el mismo motivo que a `privacidad_encargados`: no hay
 * titular al que apuntar ni que desvincular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_decisiones_automatizadas', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->foreignId('finalidad_id')->nullable()->constrained('privacidad_finalidades')->nullOnDelete();
            $table->text('descripcion');
            $table->text('logica');
            $table->text('consecuencias');
            $table->boolean('permite_revision_humana')->default(true);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['sistema', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_decisiones_automatizadas');
    }
};
