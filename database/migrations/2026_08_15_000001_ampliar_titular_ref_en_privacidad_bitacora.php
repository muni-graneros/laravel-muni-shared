<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La referencia pasa de 26 a 32 caracteres porque deja de ser un ULID.
 *
 * Un ULID mide 26 caracteres de los cuales los 10 primeros SON la marca de
 * tiempo en milisegundos: publicaba el instante exacto de la anonimización, que
 * en el sistema consumidor se junta con `personas.updated_at` —estampado en la
 * misma transacción por anonimizar()— y devuelve a la persona. El reemplazo es
 * `Str::random(32)`, que no codifica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_bitacora', function (Blueprint $table): void {
            $table->string('titular_ref', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_bitacora', function (Blueprint $table): void {
            $table->string('titular_ref', 26)->nullable()->change();
        });
    }
};
