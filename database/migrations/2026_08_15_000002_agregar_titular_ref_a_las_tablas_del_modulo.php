<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonimizar es una propiedad del grafo, no de una fila.
 *
 * La bitácora era la única tabla que podía quedar huérfana, así que el barrido
 * se detenía ahí. Pero el resto del módulo guarda el mismo morph con
 * `titular_id` NOT NULL, y una entrada huérfana de la bitácora sigue llevando
 * `datos->solicitud_id` en texto plano: desde ahí se salta a
 * privacidad_solicitudes, que todavía tenía el id de la persona, y de ahí al
 * maestro de personas federado. Dos saltos, determinista, y justamente sobre
 * las personas que ejercieron sus derechos ARCOP.
 *
 * Para cerrarlo, cada tabla con morph al titular necesita poder quedar huérfana
 * igual que la bitácora: `titular_id` nullable y la misma referencia opaca de 32
 * caracteres para seguir agrupando el caso.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLAS = [
        'privacidad_solicitudes',
        'privacidad_consentimientos',
        'privacidad_informaciones',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->string('titular_ref', 32)->nullable()->after('titular_id')->index();
            });

            // En una llamada aparte: en SQLite un change() reconstruye la tabla
            // a partir del estado del esquema, y ese estado tiene que incluir ya
            // la columna recién agregada.
            //
            // `titular_type` NO se toca, por la misma razón por la que la
            // bitácora lo conserva: un nombre de clase morph dice de qué tipo de
            // sujeto trataba la fila, no quién era.
            Schema::table($tabla, function (Blueprint $table): void {
                $table->unsignedBigInteger('titular_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropIndex(['titular_ref']);
                $table->dropColumn('titular_ref');
            });
        }

        // titular_id NO vuelve a NOT NULL: si ya se desvinculó a alguien, las
        // filas huérfanas hacen fallar la migración inversa, y rellenarlas
        // exigiría reconstruir el vínculo que este módulo existe para cortar.
    }
};
