<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;

class Textos
{
    /** Para reconocer un deadlock o una espera de candado sin listar códigos de motor a mano. */
    use DetectsConcurrencyErrors;

    /**
     * Cuántas veces se intenta publicar cuando otro publicador se llevó el
     * número de versión.
     *
     * Tres y no «hasta que salga»: dos publicaciones simultáneas del mismo
     * texto es lo que puede pasar de verdad —dos funcionarios guardando el
     * mismo aviso—, y para eso alcanza con recalcular una vez. Si choca tres
     * veces seguidas ya no es una carrera, es algo que reintentar no arregla
     * (un índice roto, un proceso publicando en bucle), y ahí conviene que
     * falle fuerte en vez de girar.
     */
    private const INTENTOS = 3;

    /**
     * Publica una versión nueva del texto y cierra la vigente.
     *
     * Sobre la carrera, escrito completo porque acá no hay candado y eso es una
     * decisión, no un olvido: el número de versión sale de `max(version) + 1`,
     * y entre esa lectura y el INSERT cabe otra publicación del mismo
     * `(sistema, codigo)`. El índice único es el árbitro —la evidencia no se
     * corrompe nunca, no pueden existir dos versiones 3—, y el perdedor se
     * lleva una violación de unicidad que antes salía como `QueryException`
     * cruda, el único fallo del módulo que no era una excepción de dominio.
     *
     * Se reintenta, y vale la pena decir por qué: la intención del perdedor
     * —«publicá este contenido como la versión siguiente»— sigue siendo
     * perfectamente satisfacible, solo que la versión siguiente ahora es otro
     * número. El intento fallido se deshace entero, cierre de vigencia
     * incluido, porque todo va dentro de la transacción; el reintento vuelve a
     * leer el estado ya con la versión del ganador publicada, le cierra la
     * vigencia a ESA y se numera detrás. Sin reintento, cada adoptante tendría
     * que escribir el suyo o mostrarle un error a un funcionario que no hizo
     * nada mal.
     *
     * QUE EL REINTENTO SIRVA NO ERA GRATIS, y acá este docblock prometía de más:
     * decía que el reintento «vuelve a leer el estado» sin decir con qué
     * aislamiento. MariaDB usa REPEATABLE READ por defecto, así que una lectura
     * común dentro de una transacción devuelve siempre el snapshot del inicio.
     * Cuando `publicar()` corre dentro de la transacción DE QUIEN LLAMA —un
     * adoptante que envuelve «publicar el aviso y anotar el acuerdo» en un
     * `DB::transaction()`—, deshacer el intento fallido solo vuelve al savepoint:
     * el snapshot sigue siendo el mismo, los tres intentos releen el mismo
     * `max(version)` viejo y los tres chocan. El reintento no resolvía nada
     * justo en el modo que el adoptante va a usar, y la suite no lo veía porque
     * simulaba al rival escribiendo en la MISMA conexión.
     *
     * Por eso las dos lecturas de las que depende el número de versión van con
     * `lockForUpdate()`, y conviene decir exactamente qué compra eso y qué no,
     * porque no es lo mismo en los dos modos de uso:
     *
     * - Cuando `publicar()` es dueño de la transacción —el modo normal—, el
     *   candado hace que el segundo publicador espere al primero y publique
     *   detrás sin chocar; y si igual choca, el intento fallido revierte la
     *   transacción ENTERA, así que el reintento arranca con una lectura nueva y
     *   ve al ganador. Ahí el reintento resuelve la carrera de verdad.
     * - Cuando corre DENTRO de la transacción de quien llama, no la resuelve, y
     *   ahora falla decente en vez de girar en falso: MariaDB 11.6 en adelante
     *   trae `innodb_snapshot_isolation` prendido, con lo que una lectura
     *   bloqueante que se topa con una fila cambiada después del snapshot no
     *   devuelve la versión nueva —como haría MySQL— sino que aborta con el 1020
     *   «Record has changed since last read». Sale como `PublicacionEnConflicto`
     *   diciendo que hay que reintentar la transacción completa, que es lo único
     *   que el motor deja hacer: quien es dueño de la transacción es el único
     *   que puede reintentarla.
     *
     * Lo que cambió respecto de la decisión anterior («no usamos FOR UPDATE
     * porque en SQLite es un no-op y quedaría lógica sin probar») es que ahora
     * hay dónde probarlo: `composer test:mariadb` corre la suite contra el motor
     * de producción y PublicacionConcurrenteEnMariaDbTest ejercita esto con DOS
     * conexiones reales, que es lo que faltaba —la prueba vieja simulaba al
     * rival en la misma conexión, donde ningún aislamiento se nota—. En SQLite
     * el grammar sigue compilando el candado a nada, y ahí la garantía la da que
     * el motor admite un solo escritor a la vez.
     *
     * @throws PublicacionEnConflicto si tres intentos seguidos chocan, o si el
     *                                motor abortó por concurrencia (1020/1205/1213)
     */
    public function publicar(string $codigo, string $contenido): TextoInformativo
    {
        $choques = 0;

        while (true) {
            try {
                return $this->publicarVersionSiguiente($codigo, $contenido);
            } catch (UniqueConstraintViolationException $e) {
                // La única unicidad de la tabla es (sistema, codigo, version),
                // así que no hay ambigüedad sobre qué chocó.
                if (++$choques >= self::INTENTOS) {
                    throw new PublicacionEnConflicto(
                        "No se pudo publicar «{$codigo}»: otra publicación se llevó el número de versión "
                        ."en {$choques} intentos seguidos. El texto vigente quedó intacto; reintentar.",
                        previous: $e,
                    );
                }
            } catch (DeadlockException|QueryException $e) {
                if (! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                // Espera de candado agotada (1205), deadlock (1213) o —en
                // MariaDB 11.6 y posteriores, que traen `innodb_snapshot_isolation`
                // prendido— el 1020 «Record has changed since last read»: ahí la
                // lectura bloqueante de adentro de la transacción de quien llama
                // NO devuelve la última versión comprometida como en MySQL, sino
                // que aborta. Comprobado contra mariadb:11.8; el motor obliga a
                // reintentar la transacción entera, y eso es lo que dice el
                // mensaje.
                //
                // Las dos clases porque Laravel usa una u otra según quién sea
                // dueño de la transacción: si `publicar()` la abrió, sale el
                // `QueryException` del driver; si está anidada en la de quien
                // llama, `ManagesTransactions` la envuelve en `DeadlockException`,
                // que no hereda de `QueryException` y se escapaba por al lado.
                //
                // Era el último fallo de este método que salía como excepción
                // cruda del driver, y con el candado nuevo es un desenlace más
                // probable que antes, así que se traduce al lenguaje del dominio.
                //
                // NO se reintenta, a diferencia del choque de unicidad, y la
                // diferencia es del motor y no del gusto: un deadlock aborta la
                // transacción ENTERA, incluida la de quien llamó, así que
                // reintentar acá adentro escribiría sobre una transacción que ya
                // no existe y el error real quedaría enterrado bajo un
                // «SAVEPOINT no existe». Quien reintenta tiene que ser quien es
                // dueño de la transacción.
                throw new PublicacionEnConflicto(
                    "No se pudo publicar «{$codigo}»: el motor abortó la operación por concurrencia "
                    .'(deadlock o espera de candado agotada) mientras otra publicación tenía tomado el mismo '
                    .'texto. El texto vigente quedó intacto; reintentar la transacción completa.',
                    previous: $e,
                );
            }
        }
    }

    public function vigente(string $codigo): ?TextoInformativo
    {
        return $this->consultaVigente($codigo)->first();
    }

    /**
     * La consulta del texto vigente, sin ejecutar.
     *
     * Separada para que `publicarVersionSiguiente()` pueda pedirla con candado
     * sin que se lo coma toda lectura: `vigente()` la usan los adoptantes para
     * mostrar el aviso en pantalla, y ahí un `FOR UPDATE` bloquearía filas por
     * cada visita.
     *
     * @return Builder<TextoInformativo>
     */
    private function consultaVigente(string $codigo): Builder
    {
        return TextoInformativo::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('codigo', $codigo)
            ->vigentes()
            ->orderByDesc('version');
    }

    /**
     * Un intento: cierra la vigente y publica la siguiente, todo o nada.
     *
     * @throws UniqueConstraintViolationException si otro publicador se adelantó
     */
    private function publicarVersionSiguiente(string $codigo, string $contenido): TextoInformativo
    {
        return DB::transaction(function () use ($codigo, $contenido): TextoInformativo {
            $sistema = (string) config('privacidad.sistema');

            // Con candado, y no por prolijidad: sin él esta lectura devuelve el
            // snapshot de la transacción de quien llama y se cierra la vigencia
            // de una versión que otro publicador ya cerró. Como el trigger deja
            // escribir `vigente_hasta` una sola vez, ese segundo cierre no es un
            // pisotón silencioso: es un error del motor.
            $anterior = $this->consultaVigente($codigo)->lockForUpdate()->first();

            if ($anterior !== null) {
                // Por query builder: el modelo es inmutable y rechazaría
                // updating. El trigger de InmutabilidadEnBaseDeDatos deja pasar
                // esta columna justamente porque cerrar una vigencia no cambia
                // lo que el titular leyó.
                TextoInformativo::query()->whereKey($anterior->getKey())
                    ->update(['vigente_hasta' => now()]);
            }

            // Mismo motivo, y este es el que hacía inútil el reintento: una
            // lectura común dentro de la transacción de quien llama devuelve
            // siempre el mismo `max(version)` viejo, así que los tres intentos
            // chocan contra el mismo número.
            $ultima = TextoInformativo::query()->delSistema($sistema)
                ->where('codigo', $codigo)->lockForUpdate()->max('version') ?? 0;

            return TextoInformativo::create([
                'sistema' => $sistema,
                'codigo' => $codigo,
                'version' => $ultima + 1,
                'contenido' => $contenido,
                'hash' => hash('sha256', $contenido),
                'vigente_desde' => now(),
            ]);
        });
    }
}
