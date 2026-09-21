<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `privacidad_solicitudes.verificacion_identidad` deja de ser JSON para poder
 * ir cifrada.
 *
 * El texto libre del módulo pasa a cifrarse en reposo (ver CifradoCast), y un
 * valor cifrado es base64, no JSON. En MariaDB ≥ 10.4.3 el tipo `JSON` es un
 * alias de LONGTEXT que además **agrega solo** `CHECK (json_valid(col))`: el
 * primer `Solicitudes::registrar()` de esta versión fallaría con la
 * restricción, en producción, con la suite del paquete en verde porque SQLite
 * no tipa las columnas. Es la clase de defecto que ya se comió una vez este
 * módulo, así que la columna cambia a LONGTEXT explícito —misma capacidad que
 * tenía— antes de que nadie escriba cifrado en ella.
 *
 * Las otras cuatro columnas cifradas (`detalle`, `fundamento_resolucion`,
 * `motivo`, `levantado_motivo`) ya son TEXT y no cambian. Lo que cambia para
 * ellas es el tope útil: TEXT admite 65.535 bytes y el cifrado agrega ~40 % más
 * unos 200 bytes de payload, así que el texto en claro más largo que cabe
 * ronda los 46 KB. Para prosa dictada en un mesón sobra.
 *
 * Las filas existentes NO se reescriben acá: eso lo hace
 * `privacidad:cifrar-texto-libre`, que se puede simular, repetir y correr
 * cuando el municipio decida. Mientras tanto el cast lee las filas viejas en
 * claro sin fallar.
 *
 * No hace falta reponer los guardias de inmutabilidad: en SQLite `change()`
 * reconstruye SOLO esta tabla, y los triggers viven en `privacidad_textos` y
 * `privacidad_bitacora`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_solicitudes', function (Blueprint $table): void {
            // Sin nullable(): la columna nació NOT NULL y change() reescribe la
            // definición completa.
            $table->longText('verificacion_identidad')->change();
        });

        $this->exigirQueNoQuedeLaRestriccionJson();
    }

    /**
     * En MariaDB el `MODIFY` reemplaza la definición de la columna y con ella
     * su CHECK implícito. Se comprueba igual, porque si por versión del motor
     * la restricción sobreviviera, la primera escritura cifrada fallaría en
     * producción y no acá: mejor que lo diga la migración.
     */
    private function exigirQueNoQuedeLaRestriccionJson(): void
    {
        if (DB::getDriverName() !== 'mariadb') {
            return;
        }

        $restantes = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'privacidad_solicitudes')
            ->where('CHECK_CLAUSE', 'like', '%json_valid%verificacion_identidad%')
            ->count();

        if ($restantes > 0) {
            throw new RuntimeException(
                'privacidad_solicitudes.verificacion_identidad conserva la restricción CHECK(json_valid) '
                .'que MariaDB agrega a las columnas JSON: con ella, el cifrado en reposo del módulo no puede '
                .'escribir. Quitarla a mano (ALTER TABLE privacidad_solicitudes DROP CONSTRAINT …) y volver a migrar.',
            );
        }
    }

    public function down(): void
    {
        // Solo es seguro antes de que haya filas cifradas: un valor cifrado no
        // es JSON válido y en MariaDB la restricción que vuelve con el tipo lo
        // rechazaría.
        Schema::table('privacidad_solicitudes', function (Blueprint $table): void {
            $table->json('verificacion_identidad')->change();
        });
    }
};
