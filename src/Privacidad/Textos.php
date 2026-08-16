<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;

class Textos
{
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
     * No se usa `SELECT … FOR UPDATE`, que sería la otra salida: en SQLite es
     * un no-op (el grammar lo compila vacío), así que la suite no lo ejercitaría
     * nunca y quedaría lógica que solo corre en producción — que es exactamente
     * cómo este módulo se ganó los comentarios que prometían cosas falsas.
     *
     * @throws PublicacionEnConflicto si tres intentos seguidos chocan
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
            }
        }
    }

    public function vigente(string $codigo): ?TextoInformativo
    {
        return TextoInformativo::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('codigo', $codigo)
            ->vigentes()
            ->orderByDesc('version')
            ->first();
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

            $anterior = $this->vigente($codigo);

            if ($anterior !== null) {
                // Por query builder: el modelo es inmutable y rechazaría
                // updating. El trigger de InmutabilidadEnBaseDeDatos deja pasar
                // esta columna justamente porque cerrar una vigencia no cambia
                // lo que el titular leyó.
                TextoInformativo::query()->whereKey($anterior->getKey())
                    ->update(['vigente_hasta' => now()]);
            }

            $ultima = TextoInformativo::query()->delSistema($sistema)
                ->where('codigo', $codigo)->max('version') ?? 0;

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
