<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * La ley pide suprimir cuando el dato ya no es necesario para la finalidad.
 * Acá eso son dos cosas distintas: los sensibles se borran de verdad y el
 * registro se anonimiza, para no perder la serie estadística comunal.
 *
 * Y una persona está terminada cuando ya no la necesita NINGUNA finalidad, no
 * cuando venció la primera. La versión anterior recorría finalidad por finalidad
 * y anonimizaba de inmediato, con lo que el plazo más corto del RAT se llevaba
 * puesto a todos los demás: en la corrida real, `agenda_citas` (24 meses)
 * anonimizó a 11.517 personas que `registro_comunal` (120 meses) tenía que
 * conservar. Los plazos de 60 y 120 declarados a la autoridad no significaban
 * nada; el único que operaba era el mínimo de la tabla.
 */
class AplicarRetencion
{
    public function __construct(
        private readonly RegistroDeEvidencia $evidencia,
        private readonly ResuelveTitularesVencidos $resolvedor,
        private readonly Bitacora $bitacora,
        private readonly SupresionEnElMaestro $maestro,
    ) {}

    /**
     * @throws SupresionNoPropagada si el sistema no declaró qué pasa con el
     *                              maestro de personas, o si el maestro rechaza
     *                              la supresión de algún titular
     */
    public function ejecutar(bool $simulacion = true): ResumenDeRetencion
    {
        $finalidades = Finalidad::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('activa', true)
            ->whereNotNull('plazo_retencion_meses')
            ->get();

        if ($finalidades->isEmpty()) {
            return new ResumenDeRetencion([], [], 0, 0, 0);
        }

        // Se comprueba ANTES de recorrer nada: si el sistema no declaró qué debe
        // pasar en el maestro, la corrida no empieza. Descubrirlo en el titular
        // número siete significaría seis identidades destruidas localmente y
        // vivas en el registro federado.
        if (! $simulacion) {
            $this->maestro->exigirDeclaracion();
        }

        [$porFinalidad, $conteo] = $this->contarVencidosPorFinalidad($finalidades);

        // La intersección: vencida en TODAS las finalidades con plazo. El conteo
        // se compara contra la cantidad de finalidades RECORRIDAS, no contra las
        // del sistema: una finalidad sin plazo (`sincronizacion_maestro`) no
        // conserva a nadie, y contarla dejaría la intersección vacía para
        // siempre.
        $suprimibles = array_keys(array_filter($conteo, fn (int $n): bool => $n === $finalidades->count()));

        $plazos = $finalidades->pluck('plazo_retencion_meses', 'codigo')
            ->map(fn ($meses): int => (int) $meses)
            ->all();

        if ($simulacion || $suprimibles === []) {
            return new ResumenDeRetencion($porFinalidad, $plazos, count($conteo), count($suprimibles), 0);
        }

        [$suprimidos, $sinPropagar] = $this->suprimir($finalidades->first(), array_flip($suprimibles), $plazos);

        return new ResumenDeRetencion(
            $porFinalidad,
            $plazos,
            count($conteo),
            count($suprimibles),
            $suprimidos,
            $sinPropagar,
        );
    }

    /**
     * Primera pasada: cuántas finalidades dan por vencida a cada persona.
     *
     * Guarda claves, no titulares: quedarse con los modelos hidratados de las
     * 20.027 personas de la corrida real para recorrerlos después es memoria que
     * no hace falta. La segunda pasada vuelve a preguntar.
     *
     * Lo que sí crece con el padrón, dicho para que no sorprenda: el conteo tiene
     * una entrada por persona vencida en al menos una finalidad. Son cadenas
     * cortas (unas decenas de bytes cada una), así que a la escala municipal
     * —decenas de miles— no es problema; a la escala de un padrón nacional
     * habría que cambiarlo por una tabla temporal.
     *
     * @param  Collection<int, Finalidad>  $finalidades
     * @return array{0: list<array{finalidad: string, titulares: int}>, 1: array<string, int>}
     */
    private function contarVencidosPorFinalidad(Collection $finalidades): array
    {
        $porFinalidad = [];
        $conteo = [];

        foreach ($finalidades as $finalidad) {
            // Set y no contador: un resolvedor que devuelva dos veces al mismo
            // titular (un join con sus atenciones, sin distinct) lo haría llegar
            // al total de finalidades por sí solo, y esa persona se anonimizaría
            // teniendo otra finalidad vigente. Es justo el defecto que esto
            // arregla, entrando por otra puerta.
            $vistos = [];

            foreach ($this->resolvedor->vencidos($finalidad) as $titular) {
                $vistos[$this->clave($titular)] = true;
            }

            // Se informan TAMBIÉN las finalidades que no vencieron a nadie, con
            // su cero. Una sola de ellas deja la intersección vacía —basta que
            // una finalidad todavía necesite a todos para que no se suprima a
            // nadie—, y sin la fila en cero eso se ve como «el comando no hizo
            // nada» sin ninguna pista de por qué.
            $porFinalidad[] = ['finalidad' => (string) $finalidad->codigo, 'titulares' => count($vistos)];

            foreach (array_keys($vistos) as $clave) {
                $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
            }
        }

        return [$porFinalidad, $conteo];
    }

    /**
     * Segunda pasada: suprimir a los de la intersección.
     *
     * Se recorre UNA finalidad cualquiera —la intersección está contenida en
     * todas— y se filtra por la lista. Recorrerlas todas volvería a visitar a la
     * misma persona una vez por finalidad.
     *
     * @param  array<string, int>  $suprimibles  claves de la intersección
     * @param  array<string, int>  $plazos  código => meses, para la evidencia
     * @return array{0: int, 1: int} cuántos se suprimieron y cuántos de ellos se
     *                               destruyeron SIN que nadie hablara con el
     *                               maestro de personas
     */
    private function suprimir(Finalidad $finalidad, array $suprimibles, array $plazos): array
    {
        // Token por corrida, aleatorio puro. Sirve para saber qué constancias
        // pertenecen a la misma corrida —sin él, tres constancias son
        // indistinguibles de tres corridas— y no dice nada de nadie: no es
        // ordenable ni lleva marca de tiempo, por lo mismo que la referencia de
        // `Bitacora::desvincular()` no es un ULID.
        $corrida = Str::random(16);
        $lote = max(1, (int) config('privacidad.retencion.lote', 100));

        // `sin_propagar_al_maestro` cuenta las supresiones en que la propagación
        // contestó «no correspondía»: el dato local se destruyó y nadie habló
        // con el registro federado. Va en el agregado de la corrida —no en la
        // constancia por titular, que sería otra vez el canal de correlación
        // del pendiente 5-ter— porque es el número que hay que mirar antes de
        // decir «este sistema propaga sus supresiones».
        $totales = [
            'titulares' => 0,
            'filas' => 0,
            'archivos_suprimidos' => 0,
            'archivos_no_encontrados' => 0,
            'sin_propagar_al_maestro' => 0,
        ];
        $publicado = 0;
        $termino = false;

        try {
            foreach ($this->resolvedor->vencidos($finalidad) as $titular) {
                if (! isset($suprimibles[$this->clave($titular)])) {
                    continue;
                }

                // El documento se lee ANTES de tocar nada: después de anonimizar
                // no queda con qué identificar a esta persona en el maestro.
                $documento = $titular->titularDocumento();

                // Primero el maestro, después lo local, y este orden es lo
                // contrario del de `Rectificaciones` a propósito. Allá la
                // propagación va dentro de la transacción porque un rechazo se
                // deshace con un rollback. Acá no: la purga borra archivos del
                // disco y ningún rollback los devuelve. Si el maestro rechaza
                // después de haber purgado, el titular queda destruido
                // localmente y vivo en el registro federado, que es exactamente
                // el defecto. Al revés —maestro primero— el peor caso es un
                // titular suprimido en el maestro y todavía completo acá, que la
                // siguiente corrida vuelve a intentar.
                $propagacion = $this->maestro->propagar($titular, $documento);

                $barrido = $this->aplicarA($titular, $plazos, $propagacion);

                $totales['titulares']++;

                if (! $propagacion->seHabloConElMaestro()) {
                    $totales['sin_propagar_al_maestro']++;
                }

                if ($barrido !== null) {
                    $totales['filas'] += $barrido->filas;
                    $totales['archivos_suprimidos'] += $barrido->archivosSuprimidos;
                    $totales['archivos_no_encontrados'] += $barrido->archivosNoEncontrados;
                }

                if ($lote <= $totales['titulares'] - $publicado) {
                    $this->publicarConstancia($corrida, $totales, $plazos, completa: false);
                    $publicado = $totales['titulares'];
                }
            }

            $termino = true;
        } finally {
            // La constancia se escribe por lote y no una sola vez al cierre,
            // porque el cierre no siempre llega. Un `finally` protege contra
            // excepciones —lo hacía, y funcionó con el 429 del maestro—, no
            // contra que maten el proceso: la corrida real murió por timeout a
            // los 10 minutos con 10.131 personas anonimizadas y CERO
            // constancias. A ~17 personas por segundo sobre el backlog de un
            // registro comunal, morir por timeout, OOM o deploy es el desenlace
            // probable, no el raro.
            //
            // Las constancias son ACUMULADAS, no sumables: cada una lleva el
            // total de la corrida hasta ese punto y la última de un `corrida`
            // dado es el total. Acumular en vez de publicar el delta del lote
            // evita además reintroducir el canal de correlación que el agregado
            // por corrida vino a cerrar: un delta final de un solo titular sería
            // otra vez «cuántas filas y cuántos documentos tenía esta persona»
            // estampado en el instante exacto de su anonimización.
            //
            // Lo que esto NO consigue, y hay que decirlo con precisión: la
            // primera constancia de una corrida chica sigue siendo el dato de
            // esas pocas personas. Lo verificado acá es que una corrida
            // interrumpida por excepción deja constancia de lo hecho y que
            // ninguna dice `completa`; un `kill -9` entre dos lotes deja lo
            // mismo que cualquier fila ya commiteada, pero eso no se ejercitó en
            // la suite.
            if ($totales['titulares'] > 0 && ($totales['titulares'] > $publicado || $termino)) {
                $this->publicarConstancia($corrida, $totales, $plazos, completa: $termino);
            }
        }

        return [$totales['titulares'], $totales['sin_propagar_al_maestro']];
    }

    /**
     * @param  array{titulares: int, filas: int, archivos_suprimidos: int, archivos_no_encontrados: int, sin_propagar_al_maestro: int}  $totales
     * @param  array<string, int>  $plazos
     */
    private function publicarConstancia(string $corrida, array $totales, array $plazos, bool $completa): void
    {
        $this->bitacora->registrarConstancia('retencion.constancia', [
            'corrida' => $corrida,
            ...$totales,
            // Qué política se estaba aplicando, con sus plazos: una constancia
            // que no lo diga no permite revisar después si la corrida barrió con
            // el criterio correcto.
            'finalidades' => $plazos,
            'completa' => $completa,
        ]);
    }

    /**
     * Todo lo local de una supresión. La propagación al maestro ya ocurrió: su
     * resultado llega para quedar escrito en la evidencia, no para decidir nada.
     *
     * @param  array<string, int>  $plazos
     */
    private function aplicarA(TitularDeDatos $titular, array $plazos, ResultadoDePropagacion $propagacion): ?ResultadoDesvinculacion
    {
        // Todo lo local, con la marca de supresión puesta: el observador `saved`
        // del sistema adoptante la consulta para NO despachar el write-through
        // al maestro (paso obligatorio de adopción), y `SincronizarAlMaestro` la
        // estampa en el job por si el observador igual lo despachó.
        return SupresionEnCurso::durante(function () use ($titular, $plazos, $propagacion): ?ResultadoDesvinculacion {
            // El orden importa: primero se borra lo sensible, después se
            // anonimiza. Al revés, el registro anonimizado podría conservar el
            // archivo sensible sin nadie a quien asociarlo para borrarlo después.
            //
            // La transacción cubre las dos escrituras de fila y la evidencia: si
            // anonimizar() o el registro de evidencia fallan, ambas se revierten y
            // no queda un dato purgado sin bitácora que pruebe que la supresión fue
            // lícita. Lo que la transacción NO cubre son los archivos en disco que
            // purgarDatosSensibles() borra: un rollback de base de datos no
            // restaura un archivo eliminado. Por eso el orden sigue siendo
            // load-bearing incluso con la transacción: si algo falla después de
            // purgar, el archivo ya no está aunque la fila vuelva a su estado
            // anterior.
            return DB::transaction(function () use ($titular, $plazos, $propagacion): ?ResultadoDesvinculacion {
                $titular->purgarDatosSensibles();
                $titular->anonimizar();

                // La entrada de evidencia se registra ANTES de desvincular, no
                // después. Registrarla después dejaría, dentro de la misma
                // transacción, una fila con titular_id vivo justo al lado de la
                // que dice titular_ref en texto plano: ids consecutivos, mismo
                // user_id, mismo instante — un join de dos saltos que en este
                // ecosistema resuelve a una identidad en el maestro de personas
                // federado, aunque el registro local ya esté anonimizado. Escrita
                // antes, esta misma entrada queda barrida por el UPDATE de
                // desvincular() de abajo: sigue existiendo, sigue diciendo
                // "retencion.aplicada", se puede seguir contando — lo único que
                // se pierde es a quién, que es exactamente el punto.
                //
                // Barrida, pero SIN referencia de grupo: esta fila nace dentro de
                // la anonimización y lleva su hora exacta, la misma que anonimizar()
                // acaba de congelar en la persona. Ponerle el titular_ref
                // encadenaría ese instante con todas las demás filas huérfanas del
                // caso. De eso se encarga la ventana de Bitacora::desvincular(); no
                // hace falta hacer nada acá, pero sí saberlo antes de mover esta
                // llamada o de agregar otra evidencia con titular en esta
                // transacción.
                //
                // El alcance de esa ventana, dicho con precisión para no repetir el
                // error de venderla como cierre: consigue que el módulo no publique
                // ningún identificador que agrupe las filas huérfanas de una
                // persona. NO consigue que el conjunto huérfano deje de ser
                // atribuible a un `personas.id`: las fechas de negocio que
                // sobreviven como hecho auditable se emparejan con
                // `personas.created_at` —que esta transacción no toca—, y el orden
                // de los ids de fila reproduce el orden de las personas
                // anonimizadas. Las dos rutas reconstruyeron 12 de 12 en el review
                // independiente. Están descritas en Bitacora::desvincular() y en el
                // pendiente 5-ter del spec, que es lo que la EIPD tiene que evaluar.
                //
                // Van TODAS las finalidades consideradas y su plazo, no la que
                // venció primero: la evidencia de una supresión tiene que poder
                // responder «¿y las otras finalidades que alcanzaban a esta
                // persona?», que es justo lo que la versión anterior no
                // preguntaba antes de destruir.
                //
                // Y qué pasó con el maestro de personas, que hasta ahora no se
                // escribía en ninguna parte: mientras `propagar()` devolvía
                // `bool`, «no lanzó» era lo único que quedaba, y eso se leía
                // como «el maestro aceptó» aunque nadie hubiera hablado con él.
                // La evidencia de una supresión tiene que poder distinguir las
                // dos cosas sin ir a mirar qué tenía enlazado ese sistema aquel
                // día.
                $this->evidencia->registrar('retencion.aplicada', [
                    'finalidades' => $plazos,
                    'propagacion' => $propagacion->paraEvidencia(),
                ], $titular instanceof Model ? $titular : null);

                // Un titular que no es Model no tiene morph con el que buscar sus
                // filas: no hay barrido y no hay cantidades que agregar.
                return $titular instanceof Model
                    ? $this->bitacora->desvincular($titular)
                    : null;
            });
        });
    }

    /**
     * Identidad estable de un titular entre las dos pasadas.
     *
     * Para un Model es su morph + clave. Un titular que no es Model no tiene
     * clave, así que se usa su documento: queda en memoria mientras dura la
     * corrida y no se persiste en ninguna parte, igual que el `$documento` que
     * viaja a `PropagaSupresion`.
     */
    private function clave(TitularDeDatos $titular): string
    {
        return $titular instanceof Model
            ? $titular->getMorphClass().'#'.$titular->getKey()
            : $titular::class.'#'.$titular->titularDocumento();
    }
}
