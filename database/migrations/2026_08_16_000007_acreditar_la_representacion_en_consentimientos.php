<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quien otorga un consentimiento por otro tiene que acreditar que puede.
 *
 * `otorgado_por` decía QUIÉN dice actuar; nada probaba que lo fuera. El régimen
 * reforzado de NNA se satisfacía eligiendo un valor en un desplegable, que es
 * exactamente lo que un régimen no puede ser. Acá va la ruta del documento —
 * certificado de nacimiento, sentencia de cuidado personal, mandato— en el
 * mismo disco declarado en `privacidad.disco_evidencia` que el resto.
 *
 * Nullable, y no es una concesión: las filas anteriores a esta columna no
 * tienen con qué llenarla y ponerles un centinela sería inventar una
 * acreditación. Lo que sí es obligatorio, desde ahora, es que
 * `Consentimientos::otorgar()` no acepte un tercero sin ella. Las filas viejas
 * quedan distinguibles justamente por estar en null.
 *
 * NO se guarda el nombre ni el RUT del representante, a propósito: las columnas
 * que el barrido de anonimización conserva en esta tabla se conservan por ser
 * categóricas (ver `Solicitante`), y meter ahí la identidad de un tercero
 * rompería ese argumento. La identidad vive en el documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            $table->string('acreditacion_path')->nullable()->after('evidencia_path');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            $table->dropColumn('acreditacion_path');
        });
    }
};
