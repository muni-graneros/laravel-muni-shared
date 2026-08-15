<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            // Guardia de unicidad, no de estado: el estado "vigente" lo sigue diciendo
            // exclusivamente `revocado_en IS NULL` (ver Consentimiento::scopeVigentes).
            // Esta columna solo existe para que sea la base de datos —y no el orden en
            // que llegan las llamadas de la aplicación— la que impida dos filas
            // vigentes para el mismo (titular, finalidad). NULL no colisiona en un
            // índice único ni en MySQL ni en SQLite, así que las filas exentas quedan
            // fuera del índice solas.
            //
            // Tres escritores, y conviene tenerlos los tres a la vista porque no se
            // mueven juntos:
            //
            //   1. `Consentimientos::otorgar()` la calcula y la escribe.
            //   2. `Consentimientos::revocar()` la limpia JUNTO con `revocado_en`. Acá
            //      sí van las dos a la vez: una fila revocada que siguiera ocupando el
            //      índice impediría otorgar un consentimiento nuevo.
            //   3. `Bitacora::desvincular()` la limpia SOLA, sin tocar `revocado_en`.
            //      Es un hash del identificador del titular —sha1(morph|id|finalidad)—,
            //      reversible por fuerza bruta contra la lista de ids del municipio, así
            //      que al anonimizar tiene que irse igual que el `titular_id`. Y
            //      `revocado_en` no se toca porque sería falso: a esa persona nadie le
            //      revocó el consentimiento, se la anonimizó.
            //
            // Consecuencia que hay que leer bien: una fila huérfana queda con
            // `vigente_clave` en null y `revocado_en` también en null, o sea "vigente"
            // sin guardia de unicidad. No es una contradicción —el índice ya no tiene
            // nada que proteger para un titular que dejó de existir, y dos huérfanas
            // con null no colisionan—, pero de acá NO se puede inferir
            // "vigente_clave IS NULL ⇒ revocado". Quien necesite el estado, que mire
            // `revocado_en`, que es el único que lo dice.
            $table->string('vigente_clave', 40)->nullable()->after('finalidad_id');
            $table->unique('vigente_clave');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            $table->dropUnique(['vigente_clave']);
            $table->dropColumn('vigente_clave');
        });
    }
};
