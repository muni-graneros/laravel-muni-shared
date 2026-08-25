<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Muni\Shared\Privacidad\InmutabilidadEnBaseDeDatos;

/**
 * `titular_id` pasa de entero a texto en las tablas del módulo.
 *
 * El morph nació como `bigint` porque los primeros sistemas del ecosistema
 * identifican a la persona por un id autoincremental. Hay sistemas que no: en
 * `atencionvecino` la clave primaria del vecino es su RUT, y MariaDB truncaba
 * «11111111-1» a 11111111 al escribirlo acá.
 *
 * Eso no es un detalle de tipos: la solicitud quedaba apuntando a un titular que
 * NO era el que vino al mesón —o a ninguno—, y el expediente que se le entrega a
 * un vecino podía traer los datos de otro. En SQLite no se veía, porque no tipa
 * las columnas: apareció recién al correr la suite contra el motor de
 * producción.
 *
 * Un número sigue cabiendo en un `varchar`, así que los sistemas que ya guardan
 * ids numéricos no cambian de comportamiento: siguen escribiendo y leyendo lo
 * mismo. Los 64 caracteres alcanzan para un id, un RUT, un UUID o un ULID.
 *
 * `titular_type` NO se toca: dice de qué tipo de sujeto trata la fila, no quién
 * es, y por eso sobrevive a la anonimización.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLAS = [
        'privacidad_solicitudes',
        'privacidad_consentimientos',
        'privacidad_informaciones',
        'privacidad_bitacora',
        'privacidad_bloqueos',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'titular_id')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table): void {
                // Nullable en todas: ya lo eran —la anonimización por retención
                // anula `titular_id` a propósito— y `change()` reescribe la
                // definición completa, así que omitirlo la volvería obligatoria.
                $table->string('titular_id', 64)->nullable()->change();
            });
        }

        $this->reponerLosGuardias();
    }

    /**
     * Volver a poner los guardias de inmutabilidad.
     *
     * En SQLite un `change()` NO altera la columna: reconstruye la tabla entera
     * —tabla nueva, copia de filas, renombre— y en esa reconstrucción se
     * **pierden los triggers**. O sea que una migración de tipos deja la
     * evidencia legal del módulo sin protección, en silencio y sin error.
     *
     * MariaDB no tiene el problema (su `ALTER ... MODIFY` conserva los
     * triggers), así que la producción del ecosistema nunca lo habría visto: lo
     * atrapó la suite en SQLite. Reponerlos igual en los dos motores es lo que
     * mantiene la promesa de que la bitácora no se puede editar.
     */
    private function reponerLosGuardias(): void
    {
        $conexion = DB::connection();

        if (! InmutabilidadEnBaseDeDatos::soporta($conexion->getDriverName())) {
            return;
        }

        InmutabilidadEnBaseDeDatos::proteger($conexion);
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'titular_id')) {
                continue;
            }

            // Vuelve a entero. Una fila cuyo titular no sea numérico —un RUT—
            // se trunca al revertir: es información que se pierde, y por eso
            // esta vuelta atrás solo es segura en un sistema que nunca guardó
            // claves no numéricas.
            Schema::table($tabla, function (Blueprint $table): void {
                $table->unsignedBigInteger('titular_id')->nullable()->change();
            });
        }

        $this->reponerLosGuardias();
    }
};
