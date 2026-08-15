<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;

/**
 * Corta el vínculo entre TODO el módulo y un titular que se anonimizó.
 *
 * Anonimizar la ficha y dejar intacta la evidencia que apunta a ella es
 * anonimización a medias: el hecho auditable tiene que sobrevivir, el vínculo no.
 *
 * Y anonimizar es una propiedad del grafo, no de una fila: barrer solo la
 * bitácora dejaba abierto el camino de dos saltos que la bitácora misma
 * documenta —una entrada huérfana lleva `datos->solicitud_id` en texto plano, y
 * privacidad_solicitudes todavía tenía el `titular_id` de la persona—. Por eso
 * el barrido cubre todas las tablas del módulo que guardan el morph.
 *
 * El nombre de la clase quedó corto respecto de lo que hace, y aun así se
 * mantiene: `Bitacora::desvincular()` es el punto de entrada que ya llaman
 * AplicarRetencion y los sistemas del ecosistema, y renombrarlo obligaría a
 * tocar repos que se despliegan por separado.
 */
class Bitacora
{
    /**
     * Tablas del módulo con morph al titular, y las columnas derivadas de su
     * identidad que hay que limpiar junto con el puntero.
     *
     * Cada tabla nueva del módulo que guarde `titular_id` va acá o la
     * anonimización nace incompleta. El test de aceptación
     * (AnonimizacionDelGrafoTest) recorre el esquema y falla si aparece una
     * columna `titular_id` que esta lista no conoce, para que no dependa de que
     * alguien se acuerde.
     *
     * @var array<string, array<string, null>>
     */
    private const TABLAS = [
        'privacidad_bitacora' => [],
        'privacidad_solicitudes' => [],
        // vigente_clave es sha1(morph|id|finalidad): un hash sobre un espacio de
        // ids chico se revierte por fuerza bruta en segundos, así que dejarlo
        // sería dejar el identificador. Limpiarlo además libera el índice único,
        // que ya no tiene nada que proteger para un titular que dejó de existir.
        'privacidad_consentimientos' => ['vigente_clave' => null],
        'privacidad_informaciones' => [],
    ];

    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /** @return int cuántas filas del módulo quedaron desvinculadas, en todas sus tablas */
    public function desvincular(Model $titular): int
    {
        return DB::transaction(function () use ($titular): int {
            // Aleatorio puro, NO un ULID. Un ULID parece la elección natural
            // —ordenable, corto, sin colisiones— y fue la que se hizo primero,
            // pero sus 10 primeros caracteres SON la marca de tiempo en
            // milisegundos: la referencia publicaba el instante exacto de la
            // anonimización, que se junta con `personas.updated_at` del sistema
            // consumidor (lo estampa anonimizar(), en esta misma transacción) y
            // vuelve a la persona. Si alguien lo "mejora" a ULID/UUIDv7 por
            // ordenabilidad, reabre esa correlación: acá no se necesita orden,
            // se necesita opacidad.
            $ref = Str::random(32);

            $afectadas = 0;

            // Por query builder a propósito: el modelo de la bitácora es
            // append-only y rechaza `updating`. Cortar el vínculo es la única
            // mutación admitida, y queda registrada abajo con su propia entrada.
            //
            // titular_type NO se limpia, a propósito: un nombre de clase morph
            // ("PersonaDePrueba", "Persona") no identifica a nadie, solo dice de
            // qué tipo de sujeto trataba la fila. Se deja para no perder ese
            // contexto sin ganar nada en anonimización.
            foreach (self::TABLAS as $tabla => $columnasDerivadas) {
                $afectadas += DB::table($tabla)
                    ->where('titular_type', $titular->getMorphClass())
                    ->where('titular_id', $titular->getKey())
                    ->update(['titular_id' => null, 'titular_ref' => $ref] + $columnasDerivadas);
            }

            if ($afectadas > 0) {
                // Esta entrada se escribe DESPUÉS del barrido y sin titular: si
                // se escribiera antes, quedaría barrida ella misma y se perdería
                // la constancia. Lo que sí es cierto del ref que viaja acá en
                // texto plano es que, terminada la transacción, ninguna fila del
                // módulo que lo comparta conserva un titular_id.
                //
                // Lo que este barrido NO hace —y no hay que leerlo como si lo
                // hiciera— es limpiar texto libre: `privacidad_solicitudes`
                // conserva `detalle` y `verificacion_identidad`, que los
                // sistemas llenan y pueden traer datos de la persona. Cortar los
                // punteros es lo que cubre este método; lo que se escribe en esos
                // campos es responsabilidad de quien los escribe.
                $this->evidencia->registrar('bitacora.desvinculada', [
                    'filas' => $afectadas,
                    'titular_ref' => $ref,
                ]);
            }

            return $afectadas;
        });
    }
}
