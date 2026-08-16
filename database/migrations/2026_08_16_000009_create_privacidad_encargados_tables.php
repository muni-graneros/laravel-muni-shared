<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién más toca estos datos, y con qué contrato.
 *
 * `Finalidad::destinatarios` era —y sigue siendo, no se toca acá— una lista
 * json de nombres. La ley exige algo que una lista de nombres no acredita:
 * un contrato con cada encargado del tratamiento y un registro de las
 * comunicaciones. Estas dos tablas son eso: el catálogo de terceros y su
 * vínculo con las finalidades para las que tratan datos.
 *
 * `privacidad_encargados` NO lleva `titular_id` ni `titular_type`, a
 * propósito y no por omisión: es un catálogo de ORGANIZACIONES —el maestro de
 * personas federado, una empresa de mensajería, otro municipio con
 * convenio—, no de titulares de datos. `Bitacora::desvincular()` barre por
 * morph al titular; una tabla sin esa columna queda fuera de su alcance sin
 * que haya que decírselo, y las guardias de deriva de
 * `AnonimizacionDelGrafoTest` (que descubren las tablas del módulo leyendo el
 * esquema, buscando `titular_id`) la dejan pasar por el mismo motivo: no hay
 * nada que desvincular de una fila que nunca apuntó a una persona.
 *
 * `contrato_path` guarda la ruta de un documento, igual que
 * `privacidad_solicitudes.respuesta_path` o
 * `privacidad_consentimientos.evidencia_path`, pero no entra a
 * `Bitacora::ARCHIVOS`: ese mecanismo borra documentos que son datos
 * personales de un titular cuando ese titular se anonimiza, y un contrato con
 * un encargado no es ninguna de las dos cosas. Es un documento SOBRE LA
 * MUNICIPALIDAD Y EL TERCERO, no sobre ningún vecino, y no tiene titular con
 * el que asociarse para que un desvincular() lo alcance. Peor aún: si
 * pudiera, sería un defecto y no una prestación —el contrato es la prueba de
 * que hubo debida diligencia sobre ese tercero, y borrarlo como efecto
 * colateral de anonimizar a un vecino cualquiera destruiría esa prueba sin
 * que nadie lo haya decidido. Su ciclo de vida (qué pasa con el archivo
 * cuando el contrato se reemplaza o el encargado se da de baja) es una
 * decisión de gestión documental propia, no de este módulo, y queda fuera de
 * este ciclo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_encargados', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->string('nombre');
            // 'encargado' trata datos por cuenta del responsable, con
            // instrucciones de este; 'destinatario' es un tercero al que se le
            // comunican datos sin pasar a tratarlos por cuenta de nadie. La
            // ley exige contrato con el primero y base habilitante para la
            // comunicación al segundo; no se cita el artículo exacto porque no
            // se verificó contra el texto vigente (a diferencia de
            // `BaseLicitud`, que sí cita el art. 12 tras esa revisión) —
            // revisar antes de producción, como el resto de lo legal en este
            // módulo que queda pendiente de confirmar (ver los plazos
            // configurables de arriba en `config/privacidad.php`).
            $table->string('rol')->default('encargado');
            $table->string('contrato_path')->nullable();
            $table->date('contrato_firmado_en')->nullable();
            $table->date('contrato_vence_en')->nullable();
            $table->string('pais')->nullable();
            $table->text('medidas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['sistema', 'activo']);
        });

        Schema::create('privacidad_encargado_finalidad', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encargado_id')->constrained('privacidad_encargados')->cascadeOnDelete();
            $table->foreignId('finalidad_id')->constrained('privacidad_finalidades')->cascadeOnDelete();

            $table->unique(['encargado_id', 'finalidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_encargado_finalidad');
        Schema::dropIfExists('privacidad_encargados');
    }
};
