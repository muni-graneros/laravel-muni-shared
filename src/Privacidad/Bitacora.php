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
     * Lo que queda escrito en una columna que se suprime al anonimizar.
     *
     * Marca visible y no un null a secas donde el esquema permite las dos: una
     * fila con «[suprimido al anonimizar]» dice que ahí HUBO algo y por qué ya
     * no está, mientras que un null se confunde con «nunca se llenó». En las
     * columnas NOT NULL además es la única opción sin tocar el esquema.
     */
    public const SUPRIMIDO = '[suprimido al anonimizar]';

    /**
     * Tablas del módulo con morph al titular, y lo que hay que limpiar en cada
     * una junto con el puntero.
     *
     * Son dos problemas distintos con la misma solución. Uno es el puntero
     * derivado: `vigente_clave` es un sha1 del identificador y un `ip_hash` es
     * un sha256 sobre 2^32 direcciones — hashes que se revierten por fuerza
     * bruta contra un espacio conocido, o sea el identificador con otra ropa.
     * El otro es el texto libre: cortar punteros no anonimiza una fila que trae
     * el RUT escrito adentro, y `verificacion_identidad` guarda justamente eso.
     *
     * Lo que NO se toca es el hecho auditable: tipo, estado, fechas, medio y la
     * referencia opaca siguen ahí. Después del barrido se tiene que poder
     * seguir diciendo «se pidió una rectificación tal día y se acogió tal
     * otro» — lo que se pierde es de quién y sobre qué. Es la misma línea que
     * la bitácora ya trazaba: nombres de campo, nunca valores.
     *
     * Cada tabla nueva del módulo que guarde `titular_id` va acá o la
     * anonimización nace incompleta. El test de aceptación
     * (AnonimizacionDelGrafoTest) recorre el esquema y falla tanto si aparece
     * una columna `titular_id` que esta lista no conoce como si aparece una
     * columna de texto libre que nadie clasificó, para que no dependa de que
     * alguien se acuerde.
     *
     * @var array<string, array<string, mixed>>
     */
    private const TABLAS = [
        // `datos` no se purga: es la evidencia misma, y su invariante —nombres
        // de campo e ids, nunca valores— la sostienen los servicios que
        // escriben ahí. Purgarla dejaría el registro sin nada que auditar. El
        // test de aceptación busca los identificadores sembrados también en
        // esta columna, así que si alguien empieza a volcar valores en la
        // bitácora, se ve.
        'privacidad_bitacora' => [],
        'privacidad_solicitudes' => [
            // Prosa dictada por el ciudadano: puede traer su RUT, su dirección
            // o el nombre de un familiar. NOT NULL, va con centinela.
            'detalle' => self::SUPRIMIDO,
            // Se conserva `metodo` («cedula_presencial») porque acredita CÓMO se
            // verificó la identidad, que es un hecho auditable y no identifica a
            // nadie; se vacía `evidencia`, que es donde va el RUN en claro.
            'verificacion_identidad->evidencia' => [],
            // La respuesta escrita al titular, con su misma exposición.
            'fundamento_resolucion' => null,
            // Una ruta de archivo suele llevar el RUT en el propio nombre, y
            // apunta a un documento con los datos de la persona. Borrar el
            // archivo es de purgarDatosSensibles() en el sistema adoptante; acá
            // se corta el puntero.
            'respuesta_path' => null,
        ],
        'privacidad_consentimientos' => [
            'vigente_clave' => null,
            'evidencia_path' => null,
            'ip_hash' => null,
        ],
        'privacidad_informaciones' => [
            'ip_hash' => null,
        ],
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
            foreach (self::TABLAS as $tabla => $aSuprimir) {
                $afectadas += DB::table($tabla)
                    ->where('titular_type', $titular->getMorphClass())
                    ->where('titular_id', $titular->getKey())
                    ->update(['titular_id' => null, 'titular_ref' => $ref] + $aSuprimir);
            }

            if ($afectadas > 0) {
                // Esta entrada se escribe DESPUÉS del barrido y sin titular: si
                // se escribiera antes, quedaría barrida ella misma y se perdería
                // la constancia. Lo que sí es cierto del ref que viaja acá en
                // texto plano es que, terminada la transacción, ninguna fila del
                // módulo que lo comparta conserva un titular_id.
                //
                // El alcance exacto, para que nadie lea de más: se cortan los
                // punteros al titular, se suprimen las columnas de texto libre
                // y los hashes derivados que la constante TABLAS enumera, y se
                // conservan los hechos auditables (tipo, estado, fechas, medio).
                // Lo que este método NO puede hacer es borrar archivos: si
                // `respuesta_path` o `evidencia_path` apuntaban a un documento
                // en disco, acá desaparece el puntero, no el documento. Eso le
                // toca a purgarDatosSensibles() del sistema adoptante, que corre
                // antes en AplicarRetencion.
                $this->evidencia->registrar('bitacora.desvinculada', [
                    'filas' => $afectadas,
                    'titular_ref' => $ref,
                ]);
            }

            return $afectadas;
        });
    }
}
