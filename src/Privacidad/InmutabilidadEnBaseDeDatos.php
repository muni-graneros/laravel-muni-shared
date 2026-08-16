<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Connection;

/**
 * Guardia de inmutabilidad en el motor, para las dos tablas de evidencia.
 *
 * Por qué existe, sin adornos: los guardias `updating` de `EntradaBitacora` y
 * `TextoInformativo` solo cubren el camino del modelo. Eloquent no dispara
 * eventos en las escrituras masivas del query builder, así que
 * `TextoInformativo::query()->update(['contenido' => '…'])` altera la prueba en
 * silencio y sin excepción. Está comprobado corriéndolo, no razonado:
 * InmutabilidadEnBaseDeDatosTest lo intenta en cada corrida.
 *
 * La pregunta que hace un fiscalizador no es «¿el código impide editarlo?» sino
 * «¿alguien pudo editarlo?», y esa se contesta donde vive el dato. De ahí el
 * trigger: rechaza el UPDATE venga de donde venga —query builder, tinker, un
 * cliente SQL— sin que el módulo tenga que ver la llamada.
 *
 * ALCANCE EXACTO, porque este módulo ya prometió de más otras veces:
 *
 * 1. Cubre UPDATE. NO cubre DELETE, y no es un olvido. Un trigger BEFORE DELETE
 *    en SQLite se dispara con `delete from …`, que es exactamente lo que
 *    compila `truncate` en ese driver (SQLiteGrammar::compileTruncate), o sea
 *    que rompería la suite de cualquier adoptante que use `DatabaseTruncation`;
 *    y en MySQL/MariaDB `TRUNCATE TABLE` no dispara triggers, así que ahí el
 *    guardia sería poroso justo contra el borrado masivo. Un guardia que rompe
 *    ocho suites y no ataja el caso grave no vale la pena: el borrado se cierra
 *    con permisos del motor —`REVOKE DELETE` sobre estas dos tablas para el
 *    usuario de la aplicación, que es paso de adopción y está anotado en
 *    docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md— y con el
 *    guardia `deleting` del modelo, que sí cubre el camino ordinario.
 * 2. No ataja a quien tenga DDL. Quien puede `DROP TRIGGER` o `DROP TABLE`
 *    puede todo; eso es un problema de grants, no de esquema.
 * 3. Solo hay SQL para sqlite y mysql/mariadb, que son los motores en que este
 *    paquete corre (tests y producción). En cualquier otro driver la migración
 *    NO instala nada y lo avisa por log: la evidencia queda con el guardia del
 *    modelo nomás. Preferimos no escribir SQL de un motor que no podemos
 *    probar, que es cómo nacieron los otros comentarios falsos de este módulo.
 * 4. El motor rechaza con su propia excepción (`QueryException`), no con
 *    `BitacoraInmutable`/`TextoInmutable`. No hay forma de traducirla sin
 *    interceptar llamadas que el módulo no ve; el mensaje del trigger dice de
 *    qué se trata.
 */
final class InmutabilidadEnBaseDeDatos
{
    /**
     * Columnas que ninguna escritura puede cambiar, por tabla.
     *
     * Lo que queda FUERA de la lista es tan deliberado como lo que está dentro,
     * porque el módulo mismo usa el query builder para dos barridos legítimos y
     * el trigger tiene que dejarlos pasar. Los punteros que esos barridos mueven
     * no quedan libres: quedan en COLUMNAS_SOLO_HACIA_NULL y
     * COLUMNAS_UNA_SOLA_VEZ, que son el mismo trigger con una condición
     * direccional. Lo que sí queda libre, y por qué:
     *
     * - `titular_type` no se protege: `desvincular()` decidió conservarlo, pero
     *   es una decisión de ese barrido y no evidencia. Protegerlo sería congelar
     *   por trigger algo que el módulo podría querer limpiar mañana.
     * - `updated_at` queda libre: no acredita nada y protegerlo haría fallar
     *   cualquier `touch()` con un error de motor en vez del error de dominio.
     * - `privacidad_textos.vigente_hasta` lo cierra `Textos::publicar()` al
     *   publicar la versión siguiente. Cerrar una vigencia no cambia lo que la
     *   persona leyó; editar el contenido sí. Queda escribible, y eso permite
     *   reescribir la línea de tiempo de qué aviso estaba vigente cuándo: es
     *   impacto bajo —el consentimiento guarda el id del texto, no la fecha— y
     *   está anotado como residuo, no como olvido.
     *
     * `sistema`, `codigo`, `version` y `vigente_desde` están protegidas junto
     * con `contenido` y `hash` porque la prueba no es solo el texto: es QUÉ
     * texto, en qué versión y desde cuándo. Mover el `codigo` de una fila
     * repunta todos los consentimientos que la apuntan sin tocar una letra del
     * contenido.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNAS = [
        'privacidad_textos' => ['sistema', 'codigo', 'version', 'contenido', 'hash', 'vigente_desde'],
        'privacidad_bitacora' => ['sistema', 'evento', 'datos', 'ocurrido_en'],
    ];

    /**
     * Punteros que solo pueden soltarse: cualquier valor → NULL, nunca al revés.
     *
     * Existe porque congelar el hecho y dejar libre la autoría no es
     * inmutabilidad, es media inmutabilidad, y la mitad que quedaba abierta era
     * la peor: con la primera versión del trigger puesta,
     * `update privacidad_bitacora set user_id = 999, titular_id = 4242` pasaba
     * sin excepción en los dos motores. O sea que se podía reasignar una acción
     * a otro funcionario, o volver a colgar de un titular cualquiera una fila ya
     * anonimizada, sin tocar una letra de QUÉ pasó. El trigger congelaba el
     * hecho y dejaba reescribible quién lo hizo.
     *
     * La condición es direccional porque los dos barridos sancionados
     * —`Bitacora::desvincular()` y `Bitacora::registrarConstancia()`— solo mueven
     * estas columnas HACIA null: cortan el vínculo, nunca lo crean ni lo mudan.
     * Así que se rechaza todo NEW que no sea NULL y difiera del OLD, con lo que
     * el barrido sigue pasando y la falsificación no.
     *
     * Es un paso más estricto que impedir solo la reasignación (viejo → otro
     * viejo): también rechaza NULL → 999, que es inventarle un autor o un titular
     * a una fila que ya se anonimizó. Falsificar sobre una fila huérfana es tan
     * falsificación como falsificar sobre una viva, y ningún flujo del módulo
     * vuelve a llenar estas columnas por UPDATE: se llenan en el INSERT, que el
     * trigger no mira.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNAS_SOLO_HACIA_NULL = [
        'privacidad_bitacora' => ['user_id', 'titular_id'],
    ];

    /**
     * Columnas que se escriben una vez y ahí quedan: NULL → valor, y nunca más.
     *
     * `titular_ref` es la referencia opaca que agrupa las filas huérfanas de una
     * misma anonimización. `desvincular()` la escribe una sola vez sobre un NULL
     * —nunca la cambia ni la borra—, así que la dirección permitida es esa y
     * ninguna otra: cambiarla mezcla los expedientes de dos personas
     * anonimizadas, y ponerla en NULL destruye la agrupación que hace legible un
     * caso completo. Las dos cosas son alteración de la evidencia.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNAS_UNA_SOLA_VEZ = [
        'privacidad_bitacora' => ['titular_ref'],
    ];

    /**
     * Mensaje con que el motor rechaza cada tabla.
     *
     * Cortos a propósito: `SIGNAL … SET MESSAGE_TEXT` de MySQL/MariaDB trunca a
     * 128 caracteres, y un mensaje cortado a la mitad es peor que uno breve.
     *
     * @var array<string, string>
     */
    public const MENSAJES = [
        'privacidad_textos' => 'privacidad_textos es inmutable: publique una version nueva, no edite la publicada.',
        'privacidad_bitacora' => 'privacidad_bitacora es append-only: no se altera el hecho ni de quien fue.',
    ];

    /** Instala el guardia. Sin efecto en drivers sin SQL propio. */
    public static function proteger(Connection $conexion): void
    {
        if (! self::soporta($driver = $conexion->getDriverName())) {
            logger()?->warning(
                "El módulo de privacidad no instaló el guardia de inmutabilidad: el driver «{$driver}» no tiene SQL propio. "
                .'Las tablas de evidencia quedan protegidas solo por los eventos del modelo, que las escrituras '
                .'masivas del query builder no disparan.',
            );

            return;
        }

        foreach (array_keys(self::MENSAJES) as $tabla) {
            $conexion->unprepared(self::sqlDeBorrado($tabla));
            $conexion->unprepared(self::sqlDeCreacion($driver, $tabla, self::MENSAJES[$tabla]));
        }
    }

    /**
     * Quita el guardia. Idempotente: sirve igual si nunca se instaló.
     *
     * Mira `soporta()` por lo mismo que `proteger()`: en un driver sin SQL
     * propio no hay trigger que borrar y el `DROP TRIGGER` sale con un error de
     * sintaxis del motor, o sea que el `down()` de la migración fallaría en un
     * adoptante que nunca tuvo el guardia puesto.
     */
    public static function desproteger(Connection $conexion): void
    {
        if (! self::soporta($conexion->getDriverName())) {
            return;
        }

        foreach (array_keys(self::MENSAJES) as $tabla) {
            $conexion->unprepared(self::sqlDeBorrado($tabla));
        }
    }

    public static function soporta(string $driver): bool
    {
        return in_array($driver, ['sqlite', 'mysql', 'mariadb'], strict: true);
    }

    public static function nombreDelTrigger(string $tabla): string
    {
        // «bu» = before update. El sufijo evita chocar con un trigger del
        // adoptante sobre la misma tabla, que no debería existir pero es gratis
        // prevenir.
        return "{$tabla}_inmutable_bu";
    }

    private static function sqlDeBorrado(string $tabla): string
    {
        return 'DROP TRIGGER IF EXISTS '.self::nombreDelTrigger($tabla);
    }

    private static function sqlDeCreacion(string $driver, string $tabla, string $mensaje): string
    {
        $trigger = self::nombreDelTrigger($tabla);
        $condicion = implode(' OR ', self::condiciones($driver, $tabla));

        if ($driver === 'sqlite') {
            return <<<SQL
                CREATE TRIGGER {$trigger}
                BEFORE UPDATE ON "{$tabla}"
                FOR EACH ROW WHEN {$condicion}
                BEGIN
                    SELECT RAISE(ABORT, '{$mensaje}');
                END
                SQL;
        }

        return <<<SQL
            CREATE TRIGGER {$trigger}
            BEFORE UPDATE ON `{$tabla}`
            FOR EACH ROW
            BEGIN
                IF {$condicion} THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$mensaje}';
                END IF;
            END
            SQL;
    }

    /**
     * Las condiciones que hacen abortar el UPDATE, una por columna protegida.
     *
     * Las tres formas dicen «cambió» de la misma manera —con la comparación
     * segura con NULL de cada motor: `IS NOT` en SQLite, `NOT (… <=> …)` en
     * MySQL/MariaDB— y se diferencian solo en qué dirección del cambio se
     * tolera. Con `=` a secas, cualquier comparación contra NULL da NULL y el
     * trigger dejaría pasar justo las alteraciones que empiezan o terminan en
     * NULL, que son las que más se parecen a un barrido legítimo.
     *
     * @return list<string>
     */
    private static function condiciones(string $driver, string $tabla): array
    {
        $sqlite = $driver === 'sqlite';
        $columna = fn (string $nombre): string => $sqlite ? "\"{$nombre}\"" : "`{$nombre}`";
        $cambio = fn (string $nombre): string => $sqlite
            ? "NEW.{$columna($nombre)} IS NOT OLD.{$columna($nombre)}"
            : "NOT (NEW.{$columna($nombre)} <=> OLD.{$columna($nombre)})";

        $condiciones = [];

        foreach (self::COLUMNAS[$tabla] ?? [] as $nombre) {
            $condiciones[] = $cambio($nombre);
        }

        foreach (self::COLUMNAS_SOLO_HACIA_NULL[$tabla] ?? [] as $nombre) {
            // Se tolera soltar el puntero (→ NULL) y nada más.
            $condiciones[] = "({$cambio($nombre)} AND NEW.{$columna($nombre)} IS NOT NULL)";
        }

        foreach (self::COLUMNAS_UNA_SOLA_VEZ[$tabla] ?? [] as $nombre) {
            // Se tolera estrenar la columna (NULL → valor) y nada más.
            $condiciones[] = "({$cambio($nombre)} AND OLD.{$columna($nombre)} IS NOT NULL)";
        }

        return $condiciones;
    }
}
