<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué se levantó una suspensión, y quién lo decidió.
 *
 * Hasta acá un bloqueo solo se podía levantar por su solicitud
 * (`Bloqueos::levantarPorSolicitud()`), y el hecho quedaba registrado sin
 * ninguna explicación: `levantado_en` con una fecha y nada más. Para el bloqueo
 * preventivo de un trámite eso alcanzaba —el porqué es la resolución de la
 * solicitud, que sí va fundada—, pero un bloqueo SIN solicitud no tenía forma
 * de levantarse en absoluto, y ahora que la tiene (`Bloqueos::levantar()`) hace
 * falta el lugar donde diga por qué.
 *
 * No se reutiliza `motivo`: ese texto explica por qué se SUSPENDIÓ el
 * tratamiento y pisarlo borraría justamente lo que un funcionario lee para
 * entender el caso. Son dos hechos distintos y van en dos columnas distintas.
 *
 * Y no va a la bitácora, que sería el lugar natural para un fundamento: su
 * invariante es «nombres de campo e ids, nunca valores», y esto es prosa
 * dictada por una persona que puede nombrar al titular o a un tercero. Va acá,
 * en la misma tabla y con el mismo tratamiento que `motivo`: `Bitacora`
 * ya la barre al anonimizar, y esta columna entra en ese barrido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_bloqueos', function (Blueprint $table): void {
            $table->text('levantado_motivo')->nullable()->after('levantado_en');
            // Quién levantó, que no es quién bloqueó: `user_id` ya guarda al
            // segundo. Sin esta columna, una fila levantada muestra un solo
            // usuario y cualquiera lo lee como el que reanudó el tratamiento.
            $table->foreignId('user_levanta_id')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_bloqueos', function (Blueprint $table): void {
            $table->dropColumn(['levantado_motivo', 'user_levanta_id']);
        });
    }
};
