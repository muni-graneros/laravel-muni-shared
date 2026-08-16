<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * Los ids de usuario (`user_id`, `user_registro_id`, `user_resolucion_id`)
     * SE SUPRIMEN, y la decisión merece quedar escrita porque la intuición dice
     * lo contrario. En un panel de funcionarios ese id es el funcionario que
     * actuó, no la persona, y conservarlo parece rendición de cuentas. Pero este
     * es un paquete compartido y no puede ver el modelo de autenticación del
     * sistema que lo adopta: `BitacoraEnBaseDeDatos` guarda `Auth::id()` sin
     * preguntar, y en un adoptante con portal ciudadano —que es hacia donde va
     * el ecosistema— `Auth::id()` ES la cuenta del propio titular. Ahí la
     * columna deja de ser trazabilidad y pasa a ser un puntero directo a la
     * persona, entero y sin hashear, que ninguna de las guardias de texto
     * detecta. Equivocarse conservando deja un identificador vivo; equivocarse
     * suprimiendo cuesta saber qué funcionario tramitó un caso YA anonimizado,
     * que además el adoptante suele tener en su propio activity_log.
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
        'privacidad_bitacora' => ['user_id' => null],
        'privacidad_solicitudes' => [
            'user_registro_id' => null,
            'user_resolucion_id' => null,
            // Prosa dictada por el ciudadano: puede traer su RUT, su dirección
            // o el nombre de un familiar. NOT NULL, va con centinela.
            'detalle' => self::SUPRIMIDO,
            // Se conserva `metodo` («cedula_presencial») porque acredita CÓMO se
            // verificó la identidad, que es un hecho auditable y no identifica a
            // nadie; se vacía `evidencia`, que es donde va el RUN en claro.
            //
            // Esta clave NO viaja al UPDATE: la resuelve purgarRutasJson() en
            // PHP, porque el `json_set(…, cast(? as json))` que compila Laravel
            // no existe en MariaDB. Ver el docblock de ese método.
            'verificacion_identidad->evidencia' => [],
            // La respuesta escrita al titular, con su misma exposición.
            'fundamento_resolucion' => null,
            // Una ruta de archivo suele llevar el RUT en el propio nombre, y
            // apunta a un documento con los datos de la persona. El archivo se
            // borra antes de anular la columna (ver ARCHIVOS): al revés queda
            // un PDF con datos personales que ya nadie sabe encontrar.
            'respuesta_path' => null,
        ],
        'privacidad_consentimientos' => [
            'vigente_clave' => null,
            'evidencia_path' => null,
            // El documento que acredita la representación es de un TERCERO
            // —certificado de nacimiento, sentencia, mandato— y nombra tanto al
            // representante como al titular. Se borra y se anula con el mismo
            // criterio que el consentimiento firmado.
            'acreditacion_path' => null,
            'ip_hash' => null,
            'user_id' => null,
        ],
        'privacidad_informaciones' => [
            'ip_hash' => null,
            'user_id' => null,
        ],
        'privacidad_bloqueos' => [
            'user_id' => null,
            // Prosa dictada por el funcionario que aplica el bloqueo («la hija
            // llamó a reclamar por el apellido», «se opone y adjunta cédula de
            // la madre»): puede nombrar al titular o a un tercero. NOT NULL,
            // va con centinela, igual que `detalle` en las solicitudes.
            //
            // `finalidad_id` y `solicitud_id` SE CONSERVAN: son llaves a
            // catálogos del propio módulo (una finalidad no es una persona, y
            // `privacidad_solicitudes` queda huérfana en la misma corrida), el
            // mismo criterio que ya conserva `finalidad_id` en
            // `privacidad_consentimientos`. Perder ese puntero no borraría
            // ningún dato adicional del titular: solo desarmaría la relación
            // «este bloqueo lo abrió esta solicitud», que es justo el hecho
            // auditable que el bloqueo existe para dejar constancia.
            'motivo' => self::SUPRIMIDO,
        ],
    ];

    /**
     * Columnas que guardan la ruta de un documento, por tabla.
     *
     * Anular la ruta sin borrar el archivo es peor que no hacer nada: el
     * expediente y el consentimiento firmado siguen en disco —siguen siendo
     * datos personales— y ya nadie sabe de quién eran ni cómo encontrarlos para
     * suprimirlos. Por eso el borrado va acá y no «después» en el adoptante:
     * después de este UPDATE, la información de qué archivo borrar ya no existe
     * en ninguna parte.
     *
     * Ojo con el reparto de responsabilidades, porque acá decía que el módulo
     * había escrito estos archivos y es falso: las dos rutas llegan de afuera
     * —`Solicitudes::acoger($respuestaPath)` y
     * `Consentimientos::otorgar(['evidencia_path' => …])`— y la única llamada a
     * `Storage` en todo `src/` es este borrado. El módulo no eligió el disco ni
     * subió el documento: borra en el disco que el adoptante declaró en
     * `privacidad.disco_evidencia`, y si esa declaración está equivocada no
     * borra nada y no se entera (ver el comentario de esa clave).
     *
     * @var array<string, list<string>>
     */
    private const ARCHIVOS = [
        'privacidad_solicitudes' => ['respuesta_path'],
        'privacidad_consentimientos' => ['evidencia_path', 'acreditacion_path'],
    ];

    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /**
     * Corta el vínculo de un titular con todas las tablas del módulo.
     *
     * Devuelve las cantidades en vez de publicarlas: son cantidades POR PERSONA
     * y la constancia se escribe en el instante de la anonimización, así que
     * publicarlas ahí es un canal de correlación (ver ResultadoDesvinculacion).
     * Quien llama decide qué hacer con ellas; `AplicarRetencion` las agrega por
     * corrida.
     *
     * @throws ArchivoNoSuprimido si el disco no confirmó borrar algún documento
     */
    public function desvincular(Model $titular): ResultadoDesvinculacion
    {
        return DB::transaction(function () use ($titular): ResultadoDesvinculacion {
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

            // Frontera entre «esto ya existía» y «esto nació dentro de la
            // anonimización».
            //
            // Una fila escrita dentro de esta misma transacción lleva estampada
            // la hora exacta en que se anonimizó, y esa hora queda congelada en
            // `personas.updated_at` del sistema consumidor porque nadie vuelve a
            // escribir esa fila nunca más. Darle el ref a esa fila publica el
            // identificador de grupo justo al lado del instante: se busca a la
            // persona por su updated_at, se cae en la fila de ese segundo y de
            // ahí se leen TODAS sus filas huérfanas, en las cinco tablas. Es la
            // misma correlación que abría el ULID, entrando por otra puerta.
            // Esas filas se desvinculan igual —pierden el titular_id y el texto
            // libre—, pero se quedan fuera del grupo.
            //
            // startOfSecond() es explícito, no load-bearing: hoy los drivers
            // formatean el binding como 'Y-m-d H:i:s' y ya descartan los
            // microsegundos, así que comparar contra now() da lo mismo (se
            // comprobó mutando esta línea: la suite queda verde). Se deja
            // escrito igual porque el que la comparación funcione no debería
            // depender de un detalle del grammar: con columnas de precisión
            // mayor, o con otro driver, la frontera tiene que seguir siendo el
            // segundo en curso.
            $ventana = now()->startOfSecond();

            $afectadas = 0;
            $suprimidos = 0;
            $noEncontrados = 0;

            // Por query builder a propósito: el modelo de la bitácora es
            // append-only y rechaza `updating`. Cortar el vínculo es la única
            // mutación admitida, y queda registrada abajo con su propia entrada.
            //
            // titular_type NO se limpia, a propósito: un nombre de clase morph
            // ("PersonaDePrueba", "Persona") no identifica a nadie, solo dice de
            // qué tipo de sujeto trataba la fila. Se deja para no perder ese
            // contexto sin ganar nada en anonimización.
            foreach (self::TABLAS as $tabla => $aSuprimir) {
                $delTitular = fn () => DB::table($tabla)
                    ->where('titular_type', $titular->getMorphClass())
                    ->where('titular_id', $titular->getKey());

                // Las claves con ruta JSON salen del UPDATE y se resuelven en
                // PHP (ver purgarRutasJson): mandarlas acá cae con un 1064 en
                // MariaDB. Se purgan ANTES de los dos UPDATE porque después el
                // titular_id ya es null y no habría por dónde encontrar la fila.
                $rutasJson = array_filter(
                    $aSuprimir,
                    fn (string $clave) => str_contains($clave, '->'),
                    ARRAY_FILTER_USE_KEY,
                );

                $aSuprimir = array_diff_key($aSuprimir, $rutasJson);

                $this->purgarRutasJson($tabla, $delTitular, $rutasJson);

                // Antes de anular las rutas, borrar los archivos: después del
                // UPDATE ya no se sabe cuáles eran. Va dentro de la transacción
                // por el mismo criterio que purgarDatosSensibles() en
                // AplicarRetencion —un rollback no devuelve un archivo
                // borrado—, y con el mismo orden load-bearing: si algo falla
                // después, el documento ya no está aunque la fila vuelva atrás.
                // Es la dirección correcta para equivocarse.
                $archivos = $this->suprimirArchivos($tabla, $delTitular());

                $suprimidos += $archivos['suprimidos'];
                $noEncontrados += $archivos['no_encontrados'];

                // La historia normal: hechos de negocio con fechas de negocio.
                // Estas sí se agrupan bajo el ref, que es lo que permite seguir
                // leyendo un caso completo sin saber de quién era.
                $afectadas += $delTitular()
                    ->where('created_at', '<', $ventana)
                    ->update(['titular_id' => null, 'titular_ref' => $ref] + $aSuprimir);

                // Lo nacido dentro de la anonimización, y también lo que no
                // tenga fecha: sin fecha no hay forma de descartar que sea de
                // este instante, y equivocarse hacia «sin ref» solo cuesta
                // agrupación, mientras que equivocarse al revés reabre la
                // correlación.
                $afectadas += $delTitular()
                    ->where(fn ($fila) => $fila->whereNull('created_at')->orWhere('created_at', '>=', $ventana))
                    ->update(['titular_id' => null, 'titular_ref' => null] + $aSuprimir);
            }

            if ($afectadas > 0) {
                // Esta entrada se escribe DESPUÉS del barrido y sin titular: si
                // se escribiera antes, quedaría barrida ella misma y se perdería
                // la constancia.
                //
                // El ref NO viaja en este payload, aunque agrupar sería cómodo
                // para depurar. Esta fila se escribe en el instante exacto de la
                // anonimización: publicar acá el ref sería exactamente lo que
                // evita la ventana de arriba —el instante y el identificador de
                // grupo, juntos y en texto plano—.
                //
                // Lo que se consigue con eso es UNA cosa, y conviene decirla sin
                // adornos porque tres rondas seguidas dijeron de más: el módulo
                // no publica ningún identificador que agrupe las filas
                // huérfanas. Ni el ref, ni un hash del titular, ni una marca de
                // tiempo ordenable.
                //
                // Lo que NO se consigue —y este barrido no puede— es impedir que
                // se atribuya un conjunto de filas huérfanas a un `personas.id`
                // del sistema adoptante. Quedan abiertas dos rutas que ni
                // siquiera miran el ref; un review independiente reconstruyó
                // persona → conjunto huérfano 12 de 12 con cada una, sobre 40
                // vecinos con 12 anonimizados en la misma corrida:
                //
                //   1. Fechas de negocio. `personas.created_at` sobrevive —es de
                //      la tabla del adoptante, este barrido no la toca y
                //      anonimizar() tampoco la anula— y se empareja por vecino
                //      más cercano con la fecha de negocio más antigua de cada
                //      grupo huérfano (`entregado_en`, `otorgado_en`,
                //      `ocurrido_en`), que sobreviven porque SON el hecho
                //      auditable. Aguanta 72 h de ruido entre una y otra.
                //   2. Ids de fila, sin ninguna fecha: ordenar los grupos
                //      huérfanos por su `privacidad_bitacora.id` más bajo y las
                //      personas anonimizadas por `id` da el mismo orden, porque
                //      el módulo siempre escribe después de que la persona
                //      existe.
                //
                // Ninguna de las dos se cierra sin destruir el valor probatorio
                // por el que este registro existe, y en este ecosistema ese
                // `personas.id` resuelve a una identidad real en el maestro de
                // personas federado. Es residuo declarado, no defecto pendiente:
                // va en la EIPD (ver el pendiente 5-ter del spec de pendientes).
                //
                // El alcance exacto, para que nadie lea de más: se cortan los
                // punteros al titular, se suprimen las columnas de texto libre,
                // los hashes derivados y los ids de usuario que la constante
                // TABLAS enumera, se borran del disco los documentos que
                // ARCHIVOS enumera, y se conservan los hechos auditables (tipo,
                // estado, fechas, medio). Lo que queda fuera del alcance —y está
                // documentado como pendiente— es el texto libre de las filas de
                // titulares que NO se anonimizaron, que nadie suprime nunca, y
                // cualquier copia de esos documentos fuera del disco
                // configurado.
                // Sin cantidades, y esto es lo que cambió el 2026-08-15. Las
                // cantidades por persona —cuántas filas, cuántos documentos—
                // publicadas en el instante exacto de la anonimización acotan
                // cada persona a un conjunto de 2 a 6 filas huérfanas, y con el
                // orden de las constancias se termina de resolver. Ahora se
                // devuelven a quien llamó y se agregan por corrida en
                // `AplicarRetencion` (evento `retencion.constancia`), donde la
                // suma sigue delatando la mala configuración que motivaba el
                // conteo —`archivos_no_encontrados` alto con
                // `archivos_suprimidos` en cero significa que
                // `privacidad.disco_evidencia` no apunta a donde el sistema
                // guarda los documentos— sin decir de quién era cada cual.
                //
                // Lo que sí queda por titular es el HECHO: se cortó el vínculo,
                // tal día y tal hora. Eso es lo que acredita la supresión ante
                // la Agencia, es idéntico para todos —no distingue a nadie— y
                // no se puede mover a la corrida sin dejar sin constancia al
                // adoptante que llame a desvincular() directo desde un «eliminar
                // mi cuenta» de su portal, que no pasa por retención.
                $this->registrarConstancia('bitacora.desvinculada', []);
            }

            return new ResultadoDesvinculacion($afectadas, $suprimidos, $noEncontrados);
        });
    }

    /**
     * Aplica en PHP las supresiones que apuntan a una clave dentro de un JSON.
     *
     * Existe por un defecto que estuvo vivo y verde: `'verificacion_identidad->
     * evidencia' => []` dentro de un `update()` hace que la gramática de Laravel
     * compile `json_set(col, '$."evidencia"', cast(? as json))`, y **MariaDB no
     * soporta `CAST(x AS JSON)`** —es sintaxis de MySQL; en MariaDB `JSON` es un
     * alias de LONGTEXT, no un tipo casteable—, así que el motor devuelve un
     * 1064 al preparar la sentencia. `MariaDbGrammar` hereda
     * `compileJsonUpdateColumn` de `MySqlGrammar` sin corregirlo. Resultado: la
     * retención entera caída en producción con la suite del paquete en verde,
     * porque la suite corría en SQLite.
     *
     * Se leen las filas y se reescribe la columna completa desde PHP en vez de
     * emitir una expresión distinta por driver. Cuesta N sentencias por titular
     * en vez de una, y se acepta: la retención procesa de a una persona, y —lo
     * que de verdad importa— la semántica de qué sobrevive a la purga queda
     * escrita en PHP, donde se prueba igual en los dos motores. Una expresión
     * condicionada al driver es exactamente la forma en que nació este defecto.
     *
     * La clave se conserva en TABLAS con su ruta («columna->clave») porque es la
     * declaración que leen las guardias de deriva de AnonimizacionDelGrafoTest:
     * sacarla de ahí dejaría `verificacion_identidad` sin clasificar.
     *
     * Si el documento no se puede leer como array, se reescribe con las claves
     * purgadas y nada más: se pierde el `metodo`, que no identifica a nadie, y
     * no se conserva un contenido que nadie pudo inspeccionar. La columna es NOT
     * NULL, así que dejarla intacta no es opción.
     *
     * @param  callable(): Builder  $filas
     * @param  array<string, mixed>  $rutas  ['columna->clave' => valor nuevo]
     */
    private function purgarRutasJson(string $tabla, callable $filas, array $rutas): void
    {
        if ($rutas === []) {
            return;
        }

        /** @var array<string, array<string, mixed>> $porColumna */
        $porColumna = [];

        foreach ($rutas as $ruta => $valor) {
            [$columna, $clave] = explode('->', $ruta, 2);
            $porColumna[$columna][$clave] = $valor;
        }

        foreach ($porColumna as $columna => $claves) {
            foreach ($filas()->select('id', $columna)->get() as $fila) {
                $documento = json_decode((string) $fila->{$columna}, true);
                $documento = is_array($documento) ? $documento : [];

                foreach ($claves as $clave => $valor) {
                    // La ruta puede tener más de un nivel («a->b->c»); Arr::set
                    // la resuelve con la notación de punto.
                    Arr::set($documento, str_replace('->', '.', $clave), $valor);
                }

                DB::table($tabla)
                    ->where('id', $fila->id)
                    ->update([$columna => json_encode($documento)]);
            }
        }
    }

    /**
     * Deja una constancia del propio módulo, sin titular y sin usuario.
     *
     * Sin usuario a propósito: la evidencia la escribe `registrar()`, que guarda
     * Auth::id() sin preguntar. Normalmente es null porque la retención corre
     * por cron, pero un adoptante que ofrezca «eliminar mi cuenta» en su portal
     * ciudadano la dispararía con el propio titular autenticado, y quedaría su
     * id de usuario en la fila del instante exacto de la anonimización: el mismo
     * camino que cierra la ventana de desvincular(), entrando por un entero.
     * Vale igual para el agregado por corrida: una corrida con un solo titular
     * lo delataría igual.
     *
     * Se acota por id y por evento para no tocar la evidencia de otra petición
     * que esté escribiendo en el mismo segundo. Si el adoptante sustituyó
     * `RegistroDeEvidencia` por su propia bitácora, el UPDATE no encuentra nada
     * y no hace daño; es responsabilidad de esa implementación no guardar el
     * usuario.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrarConstancia(string $evento, array $datos): void
    {
        $ultima = (int) DB::table('privacidad_bitacora')->max('id');

        $this->evidencia->registrar($evento, $datos);

        DB::table('privacidad_bitacora')
            ->where('id', '>', $ultima)
            ->where('evento', $evento)
            ->update(['user_id' => null]);
    }

    /**
     * Borra del disco los documentos referenciados por las filas que se van a
     * desvincular.
     *
     * Solo cuenta como suprimido lo que el disco confirmó haber borrado. Contar
     * el intento —que es lo que hacía antes, ignorando el retorno de delete()—
     * produce el peor estado posible de esta feature, y está reproducido con un
     * directorio de solo lectura: la constancia dice «archivos_suprimidos: 1»,
     * el PDF sigue en disco y la columna con su ruta ya está anulada.
     *
     * Un fallo aborta la transacción entera en vez de seguir con la ruta
     * intacta. Conservar la ruta parece más suave —el documento sigue siendo
     * localizable para borrarlo después— pero deja el RUT que suele venir en el
     * nombre del archivo dentro de una fila que el resto del barrido ya dejó
     * huérfana: anonimización a medias, y justo la que contradice la garantía de
     * que ninguna columna sobreviviente identifica al titular. Con el throw todo
     * vuelve atrás —la persona no queda marcada como anonimizada, la fila
     * conserva su titular y su ruta— y la corrida se repite cuando el entorno
     * esté arreglado. Lo único que no vuelve son los archivos ya borrados de
     * este mismo titular, que reaparecen como `no_encontrados` en el reintento.
     *
     * @param  Builder  $filas  consulta ya acotada al titular
     * @return array{suprimidos: int, no_encontrados: int}
     *
     * @throws ArchivoNoSuprimido si el disco no confirmó alguna supresión
     */
    private function suprimirArchivos(string $tabla, Builder $filas): array
    {
        $suprimidos = 0;
        $noEncontrados = 0;
        $fallidos = [];

        $nombreDisco = (string) config('privacidad.disco_evidencia');
        $disco = Storage::disk($nombreDisco);

        foreach (self::ARCHIVOS[$tabla] ?? [] as $columna) {
            foreach ($filas->clone()->whereNotNull($columna)->pluck($columna) as $ruta) {
                // Un archivo que ya no está no es un error: pudo borrarlo la
                // purga de sensibles del sistema adoptante, que corre antes. Se
                // cuenta, eso sí, porque el mismo síntoma aparece cuando el
                // disco configurado no es el correcto.
                if (! $disco->exists((string) $ruta)) {
                    $noEncontrados++;

                    continue;
                }

                // Bucket propio: «estaba y sigue estando» no es lo mismo que «ya
                // no estaba», y confundirlos es lo que hacía pasar un fallo por
                // un éxito.
                if (! $disco->delete((string) $ruta)) {
                    $fallidos[] = "{$tabla}.{$columna}";

                    continue;
                }

                $suprimidos++;
            }
        }

        if ($fallidos !== []) {
            $cuantos = count($fallidos);
            $columnas = implode(', ', array_unique($fallidos));

            throw new ArchivoNoSuprimido(
                "El disco «{$nombreDisco}» no pudo borrar {$cuantos} documento(s) ({$columnas}). "
                .'La anonimización se revierte entera: anular la ruta dejaría el documento con datos '
                .'personales en disco y sin forma de encontrarlo. Revisar permisos y volver a correr.',
            );
        }

        return ['suprimidos' => $suprimidos, 'no_encontrados' => $noEncontrados];
    }
}
