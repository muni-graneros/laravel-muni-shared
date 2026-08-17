<?php

namespace Muni\Shared\Privacidad;

use Throwable;

/**
 * Marca de proceso: «lo que se está escribiendo AHORA es una supresión».
 *
 * Existe por un defecto que ninguna de las dos mitades tenía por separado.
 * `anonimizar()` termina en un `save()`; los sistemas del ecosistema tienen un
 * observador `Persona::saved` que empuja la persona al maestro federado. Medido
 * contra un maestro real: cada anonimización mandaba DOS altas al maestro —la
 * identidad completa, empujada por el `save()` de purgar, y después un
 * `ANON-{id}` como persona nueva—, o sea que la retención daba de alta en el
 * registro central justo a quien acababa de suprimir localmente.
 *
 * Por qué una marca de proceso y no `Model::withoutEvents()`, que era la
 * respuesta obvia: apagar todos los eventos del modelo dentro de la supresión
 * apaga también los guardias del propio módulo —`EntradaBitacora` rechaza
 * `updating` y `deleting` por evento— y cualquier hook del adoptante del que
 * dependa su propia purga (borrado de archivos en `forceDeleted`, columnas
 * normalizadas en `saving`). Se apagaría exactamente el camino destructivo, que
 * es donde más se necesitan. La marca es lo contrario: no apaga nada, deja que
 * cada quien decida.
 *
 * Alcance, dicho con precisión para no prometer de más: esta clase por sí sola
 * no impide ningún envío. Impide los que consultan la marca. Hoy la consultan
 * `SincronizarAlMaestro` —el transporte del ecosistema, que además la lleva
 * estampada en el job para sobrevivir a la cola— y el observador del sistema
 * adoptante, que tiene que consultarla y es paso OBLIGATORIO de adopción
 * (README, «Supresión y write-through»). Un sistema que empuje al maestro por
 * otro camino sin consultarla vuelve a tener el defecto completo.
 */
final class SupresionEnCurso
{
    /**
     * Contador y no booleano: la supresión de un titular puede anidar (una
     * implementación de `purgarDatosSensibles()` que a su vez use el contexto),
     * y con un booleano el `finally` del más interno apagaría la marca del
     * externo, dejando el resto de la supresión visible para el write-through.
     */
    private static int $profundidad = 0;

    public static function activa(): bool
    {
        return self::$profundidad > 0;
    }

    /**
     * Corre $accion con la marca puesta.
     *
     * @template TRetorno
     *
     * @param  callable(): TRetorno  $accion
     * @return TRetorno
     *
     * @throws Throwable lo que sea que lance $accion, con la marca ya apagada
     */
    public static function durante(callable $accion): mixed
    {
        self::$profundidad++;

        try {
            return $accion();
        } finally {
            // En `finally` porque una supresión que revienta a mitad —un archivo
            // que el disco no borró— dejaría la marca puesta para todo el resto
            // del proceso: el sistema seguiría corriendo y ya no sincronizaría
            // NADA al maestro, en silencio y hasta el próximo reinicio. Bajo
            // Octane ese proceso vive días.
            self::$profundidad--;
        }
    }
}
