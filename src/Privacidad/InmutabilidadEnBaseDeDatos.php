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
     * el trigger tiene que dejarlos pasar:
     *
     * - `privacidad_textos.vigente_hasta` lo cierra `Textos::publicar()` al
     *   publicar la versión siguiente. Cerrar una vigencia no cambia lo que la
     *   persona leyó; editar el contenido sí.
     * - `privacidad_bitacora.titular_id`, `titular_ref` y `user_id` los barre
     *   `Bitacora::desvincular()` al anonimizar. Ahí la ley obliga a cortar el
     *   vínculo, y el hecho auditable —qué pasó, con qué datos, cuándo— es
     *   justamente lo que tiene que sobrevivir: por eso `evento`, `datos` y
     *   `ocurrido_en` están en la lista y el puntero al titular no.
     * - `titular_type` tampoco se protege: `desvincular()` decidió conservarlo,
     *   pero es una decisión de ese barrido y no evidencia. Protegerlo sería
     *   congelar por trigger algo que el módulo podría querer limpiar mañana.
     * - `updated_at` queda libre: no acredita nada y protegerlo haría fallar
     *   cualquier `touch()` con un error de motor en vez del error de dominio.
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
     * Mensaje con que el motor rechaza cada tabla.
     *
     * Cortos a propósito: `SIGNAL … SET MESSAGE_TEXT` de MySQL/MariaDB trunca a
     * 128 caracteres, y un mensaje cortado a la mitad es peor que uno breve.
     *
     * @var array<string, string>
     */
    public const MENSAJES = [
        'privacidad_textos' => 'privacidad_textos es inmutable: publique una version nueva, no edite la publicada.',
        'privacidad_bitacora' => 'privacidad_bitacora es append-only: la evidencia del tratamiento no se altera.',
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

        foreach (self::COLUMNAS as $tabla => $columnas) {
            $conexion->unprepared(self::sqlDeBorrado($tabla));
            $conexion->unprepared(self::sqlDeCreacion($driver, $tabla, $columnas, self::MENSAJES[$tabla]));
        }
    }

    /** Quita el guardia. Idempotente: sirve igual si nunca se instaló. */
    public static function desproteger(Connection $conexion): void
    {
        foreach (array_keys(self::COLUMNAS) as $tabla) {
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

    /**
     * @param  list<string>  $columnas
     */
    private static function sqlDeCreacion(string $driver, string $tabla, array $columnas, string $mensaje): string
    {
        $trigger = self::nombreDelTrigger($tabla);

        if ($driver === 'sqlite') {
            // `IS NOT` en SQLite compara con NULL incluido: sin eso, cambiar una
            // columna nullable de NULL a un valor daría NULL en la condición y
            // el trigger dejaría pasar la alteración.
            $condicion = implode(' OR ', array_map(
                fn (string $columna): string => "NEW.\"{$columna}\" IS NOT OLD.\"{$columna}\"",
                $columnas,
            ));

            return <<<SQL
                CREATE TRIGGER {$trigger}
                BEFORE UPDATE ON "{$tabla}"
                FOR EACH ROW WHEN {$condicion}
                BEGIN
                    SELECT RAISE(ABORT, '{$mensaje}');
                END
                SQL;
        }

        // `<=>` es el igual seguro con NULL de MySQL/MariaDB, el equivalente al
        // `IS NOT` de SQLite.
        $condicion = implode(' OR ', array_map(
            fn (string $columna): string => "NOT (NEW.`{$columna}` <=> OLD.`{$columna}`)",
            $columnas,
        ));

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
}
