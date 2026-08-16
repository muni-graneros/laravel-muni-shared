<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo mismo que en los consentimientos, para el ejercicio de los derechos.
 *
 * `privacidad_solicitudes.solicitante` decía desde el principio si actuaba el
 * titular o un tercero, y nada probaba la representación: quien elegía
 * «representante legal» en el desplegable se llevaba la copia íntegra del
 * registro de una persona ajena, o le hacía suprimir su inscripción. La
 * verificación de identidad que ya existía acredita QUIÉN es el que está al
 * otro lado del mesón, no que pueda actuar POR OTRO.
 *
 * Nullable por la misma razón que allá: las solicitudes anteriores a esta
 * columna no tienen con qué llenarla, y `Solicitudes::registrar()` es quien
 * exige de acá en adelante. Tampoco se guarda la identidad del representante:
 * vive en el documento (ver la migración hermana de consentimientos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_solicitudes', function (Blueprint $table): void {
            $table->string('acreditacion_path')->nullable()->after('respuesta_path');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_solicitudes', function (Blueprint $table): void {
            $table->dropColumn('acreditacion_path');
        });
    }
};
