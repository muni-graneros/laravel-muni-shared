<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * Registra que un tratamiento queda suspendido mientras hay una disputa
 * abierta, y ofrece la consulta para que el sistema adoptante decida qué
 * hacer con eso.
 *
 * Lo que esta clase NO hace, dicho sin adornos: no impide que un sistema siga
 * leyendo o escribiendo sobre el titular bloqueado. No hay scope global, no
 * hay interceptor de queries, no hay nada que se dispare solo. `vigente()` es
 * una pregunta que alguien tiene que hacer antes de tratar el dato; si nadie
 * la hace, el bloqueo queda registrado en la tabla y en nada más. Que el
 * sistema la consulte es obligación de la adopción, no algo que este módulo
 * pueda garantizar desde acá —y va como punto verificable del plan de
 * adopción, no como promesa de este código.
 *
 * ## Hasta dónde alcanza un bloqueo: al sistema, no al ecosistema
 *
 * `privacidad_bloqueos` es UNA tabla compartida por los ocho sistemas
 * municipales. `bloquear()` siempre escribió en qué sistema se puso el bloqueo;
 * `vigente()` no lo consultaba, así que una oposición acogida en licencias
 * cesaba el tratamiento en discapacidad y al revés. No era una decisión: era
 * una columna escrita y no leída.
 *
 * Peor, era INCOHERENTE consigo misma. Un bloqueo acotado a una finalidad ya
 * era de hecho local —`finalidad_id` apunta a `privacidad_finalidades`, que es
 * única por `(sistema, codigo)`, así que la finalidad de un sistema nunca
 * coincide con la de otro—, mientras que el bloqueo SIN finalidad, que es el
 * más amplio, era el único que cruzaba a los ocho. O sea: la oposición acotada
 * a una finalidad quedaba encerrada en su sistema y la oposición general se
 * derramaba a todos. Exactamente al revés de cualquier criterio, y por
 * accidente del esquema.
 *
 * La decisión, y su porqué: **el bloqueo alcanza al sistema en que se
 * presentó**. Los tres motivos, en orden de peso:
 *
 * 1. **Un cese que nadie pidió deja a alguien sin atención.** Cesar de más y
 *    cesar de menos NO son simétricos: cesar de menos incumple un derecho ya
 *    ejercido y el titular puede reclamarlo —queda registro de que lo ejerció—;
 *    cesar de más le corta a un vecino una prestación municipal que la ley
 *    obliga a dar, sobre finalidades que él nunca discutió, y sin que nadie
 *    sepa por qué. Lo segundo se descubre en el mesón y no deja rastro de
 *    haberse decidido.
 * 2. **Lo que el titular ejerció es acotado, y el módulo no puede ampliarlo por
 *    adivinanza.** Una oposición se presenta contra tratamientos concretos, y
 *    esos tratamientos son las finalidades del RAT del sistema que la recibió.
 *    Qué finalidad de licencias corresponde a cuál de discapacidad no lo sabe
 *    este paquete, y no hay dato en la tabla del que deducirlo.
 * 3. **Es lo que ya hace el resto del módulo.** Finalidades, textos,
 *    solicitudes y el RAT están todos delimitados por `privacidad.sistema`. Un
 *    bloqueo de alcance ecosistémico sería la única pieza que rompe esa
 *    frontera, y la rompería sin ningún registro de haberlo decidido.
 *
 * **Lo que esta decisión NO resuelve, dicho antes de que alguien lo lea como
 * cerrado:** que una persona ejerza su derecho ante EL MUNICIPIO —que es el
 * responsable del tratamiento, no cada sistema por separado— y el municipio lo
 * respete en una sola ventanilla. Ese es un problema de PROCEDIMIENTO y de
 * decisión municipal, no algo que este código pueda zanjar: hoy la vía correcta
 * es registrar la solicitud en cada sistema donde el titular quiera que opere.
 * Lo que el módulo aporta es que deje de ser invisible:
 * `sistemasConBloqueoVigente()` muestra en qué otros sistemas hay una
 * suspensión vigente sobre el mismo titular. Está anotado como pendiente en
 * `docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md`.
 *
 * **Compatibilidad con las filas que ya existen.** Ninguna migración hace falta:
 * `bloquear()` siempre escribió `sistema`, así que toda fila existente ya dice a
 * qué sistema pertenece. Lo que SÍ cambia es su significado efectivo: un bloqueo
 * sin finalidad que hasta hoy frenaba —a quien consultara— en los ocho sistemas,
 * desde ahora frena solo en el suyo. Si algún municipio venía apoyándose en ese
 * derrame para dar por cesado un tratamiento en otro sistema, ese cese hay que
 * volver a decidirlo y registrarlo donde corresponde.
 */
class Bloqueos
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    public function bloquear(
        Model $titular,
        ?Finalidad $finalidad,
        string $motivo,
        ?Solicitud $solicitud = null,
    ): Bloqueo {
        return DB::transaction(function () use ($titular, $finalidad, $motivo, $solicitud): Bloqueo {
            $bloqueo = Bloqueo::create([
                'sistema' => (string) config('privacidad.sistema'),
                'titular_type' => $titular->getMorphClass(),
                'titular_id' => $titular->getKey(),
                'finalidad_id' => $finalidad?->getKey(),
                'solicitud_id' => $solicitud?->getKey(),
                'motivo' => $motivo,
                'desde' => now(),
                'user_id' => Auth::id(),
            ]);

            $this->evidencia->registrar('bloqueo.aplicado', [
                'finalidad' => $finalidad?->codigo,
                'solicitud_id' => $solicitud?->getKey(),
            ], $titular);

            return $bloqueo;
        });
    }

    /**
     * Levanta UN bloqueo, diciendo por qué.
     *
     * Existe porque `levantarPorSolicitud()` era la única vía y dejaba un
     * callejón sin salida: un bloqueo puesto a mano —o el que crea
     * `volverDefinitivos()` cuando `bloquear_durante_solicitud` está apagada, que
     * nace ligado a una solicitud ya resuelta— no se podía levantar por ninguna
     * puerta del módulo. El sistema adoptante terminaba haciendo un UPDATE
     * directo sobre una tabla del paquete, sin constancia de nada.
     *
     * Tres cosas que este método decide, y su porqué:
     *
     * 1. **Exige el motivo.** Levantar un cese es reanudar el tratamiento de
     *    alguien que había obtenido que se detuviera. El módulo exige
     *    `fundamento` en toda resolución de solicitud por la misma razón, y una
     *    suspensión que termina sin ninguna explicación es exactamente lo que no
     *    se puede mostrar en una fiscalización.
     * 2. **No pisa `motivo`.** Ese texto dice por qué se suspendió y es lo único
     *    que un funcionario lee para entender el caso; el porqué del
     *    levantamiento es otro hecho y va en su propia columna.
     * 3. **Un sistema no levanta el bloqueo de otro.** Es la contraparte de la
     *    decisión de alcance del docblock de la clase: si el bloqueo de licencias
     *    no cesa el tratamiento en discapacidad, discapacidad tampoco puede
     *    reanudar lo que licencias detuvo.
     *
     * Lo que NO hace, para no venderlo de más: no comprueba que la solicitud que
     * originó el bloqueo esté resuelta. Levantar el bloqueo preventivo de un
     * trámite abierto es legítimo —la jefatura puede considerar la suspensión
     * desproporcionada— y prohibirlo dejaría sin salida a un caso real. Lo que el
     * módulo garantiza es que quede escrito quién lo hizo y por qué.
     *
     * @return bool false si ya estaba levantado, sin reescribir nada
     *
     * @throws ResolucionInvalida si va sin motivo, o si el bloqueo es de otro
     *                            sistema del ecosistema
     */
    public function levantar(Bloqueo $bloqueo, string $motivo): bool
    {
        $sistema = (string) config('privacidad.sistema');

        if ($bloqueo->sistema !== $sistema) {
            throw new ResolucionInvalida(
                "El bloqueo #{$bloqueo->getKey()} lo puso el sistema «{$bloqueo->sistema}» y este es "
                ."«{$sistema}»: el cese del tratamiento lo levanta quien lo decidió, no otra ventanilla.",
            );
        }

        if (trim($motivo) === '') {
            throw new ResolucionInvalida(
                "No se puede levantar el bloqueo #{$bloqueo->getKey()} sin decir por qué: reanudar un "
                .'tratamiento que estaba suspendido es una decisión que hay que poder explicar después.',
            );
        }

        if ($bloqueo->levantado_en !== null) {
            return false;
        }

        return DB::transaction(function () use ($bloqueo, $motivo): bool {
            // Condicionado a `vigentes()` y no un save() sobre la instancia: dos
            // funcionarios levantando el mismo bloqueo en el mesón, y el segundo
            // pisaría la fecha y el motivo del primero con los suyos. Así el
            // segundo no escribe nada y se entera por el `false`.
            $afectados = Bloqueo::query()
                ->whereKey($bloqueo->getKey())
                ->vigentes()
                ->update([
                    'levantado_en' => now(),
                    // Un update() sobre el builder no pasa por los casts del
                    // modelo: se cifra a mano, con el mismo cast que lee la
                    // columna. Sin esto el motivo quedaba en claro con el cast
                    // declarado y la suite en verde.
                    'levantado_motivo' => CifradoCast::cifrar($motivo),
                    'user_levanta_id' => Auth::id(),
                ]);

            if ($afectados === 0) {
                return false;
            }

            // El MISMO evento que `levantarPorSolicitud()` y con sus dos claves,
            // para que «acá se levantó un bloqueo» se busque siempre igual; se
            // suma `bloqueo_id`, que allá no existe porque allá se levantan
            // varios de una vez.
            //
            // El motivo NO viaja: la invariante de `privacidad_bitacora.datos`
            // es nombres de campo e ids, nunca prosa, y esta prosa la dicta un
            // funcionario y puede nombrar al titular o a un tercero. Vive en la
            // columna, que el barrido de `Bitacora::desvincular()` sí alcanza.
            $this->evidencia->registrar('bloqueo.levantado', [
                'bloqueo_id' => $bloqueo->getKey(),
                'solicitud_id' => $bloqueo->solicitud_id,
                'bloqueos' => 1,
            ], $bloqueo->titular);

            $bloqueo->refresh();

            return true;
        });
    }

    /**
     * Levanta todos los bloqueos preventivos de una solicitud al resolverla.
     *
     * Acá el porqué del levantamiento NO va en `levantado_motivo` a propósito:
     * es el `fundamento_resolucion` de la solicitud, que ya está escrito, fundado
     * y ligado por `solicitud_id`. Copiarlo sería duplicar el mismo texto de un
     * titular en dos tablas, que es justo lo que este módulo minimiza.
     *
     * @return int cuántos bloqueos quedaron levantados
     */
    public function levantarPorSolicitud(Solicitud $solicitud): int
    {
        return DB::transaction(function () use ($solicitud): int {
            $afectados = Bloqueo::query()
                ->where('solicitud_id', $solicitud->getKey())
                ->vigentes()
                ->update(['levantado_en' => now(), 'user_levanta_id' => Auth::id()]);

            if ($afectados > 0) {
                $this->evidencia->registrar('bloqueo.levantado', [
                    'solicitud_id' => $solicitud->getKey(),
                    'bloqueos' => $afectados,
                ], $solicitud->titular);
            }

            return $afectados;
        });
    }

    /**
     * El bloqueo preventivo de una solicitud pasa a ser el efecto de haberla
     * acogido: el tratamiento cesa y el bloqueo no se levanta.
     *
     * Dos cosas que parecen una sola y no lo son:
     *
     * 1. **Reescribir el motivo.** El que puso `Solicitudes::registrar()` dice
     *    «Solicitud de Oposición en trámite», y después de la resolución eso ya
     *    no es cierto: quien lea la tabla vería un trámite abierto donde hay una
     *    decisión tomada. El motivo es lo único que un funcionario lee para
     *    saber por qué no puede tratar ese dato.
     * 2. **Crear el bloqueo si no había ninguno.** `bloquear_durante_solicitud`
     *    es configurable y puede estar apagada; sin esto, acoger una oposición
     *    en un sistema con la bandera en `false` no tendría NINGÚN efecto sobre
     *    el tratamiento —la solicitud quedaría «acogida» y el sistema seguiría
     *    tratando el dato igual que antes—, que es la misma clase de defecto que
     *    este método viene a cerrar.
     *
     * El bloqueo nuevo va SIN finalidad (alcanza a todas): una oposición que el
     * municipio acogió sin acotarla no se puede acotar acá por adivinanza.
     *
     * Lo que este método NO consigue, y vale para todos los bloqueos: que el
     * sistema adoptante deje de tratar el dato. Escribe la fila; quien tiene que
     * consultarla es el adoptante (ver el docblock de la clase).
     *
     * @return int cuántos bloqueos quedaron definitivos
     */
    public function volverDefinitivos(Solicitud $solicitud, string $motivo): int
    {
        return DB::transaction(function () use ($solicitud, $motivo): int {
            $afectados = Bloqueo::query()
                ->where('solicitud_id', $solicitud->getKey())
                ->vigentes()
                // Cifrado a mano por lo mismo que en levantar(): el update()
                // masivo no pasa por los casts.
                ->update(['motivo' => CifradoCast::cifrar($motivo)]);

            $titular = $solicitud->titular;

            // Un titular anonimizado no tiene tratamiento que hacer cesar: sus
            // datos ya no están. La solicitud se resuelve igual —el hecho de
            // que se acogió es auditable— pero no se crea un bloqueo que
            // apuntaría a una fila que ya no identifica a nadie.
            if ($afectados === 0 && $titular instanceof Model) {
                $this->bloquear($titular, null, $motivo, $solicitud);
                $afectados = 1;
            }

            // La constancia se escribe en los dos caminos —el bloqueo que ya
            // existía y el que hubo que crear— para que «acá cesó el
            // tratamiento» se busque siempre por el mismo evento. Por el
            // camino de la creación queda además su `bloqueo.aplicado`, que
            // dice otra cosa: que la fila nació ahí.
            if ($afectados > 0) {
                $this->evidencia->registrar('bloqueo.definitivo', [
                    'solicitud_id' => $solicitud->getKey(),
                    'bloqueos' => $afectados,
                ], $titular);
            }

            return $afectados;
        });
    }

    /**
     * Si hay un bloqueo vigente **de este sistema** que alcance a la finalidad
     * dada (o uno sin finalidad, que alcanza a todas). Solo consulta: no impide
     * nada por sí misma, ver el docblock de la clase.
     *
     * El filtro por sistema no estaba y su ausencia era el defecto más grave del
     * módulo: `bloquear()` escribía la columna y esta consulta no la miraba, con
     * la tabla compartida por los ocho sistemas del ecosistema. Ver el docblock
     * de la clase para el alcance que se eligió y por qué.
     */
    public function vigente(Model $titular, ?Finalidad $finalidad = null): bool
    {
        return $this->deEsteTitular($titular)
            ->delSistema((string) config('privacidad.sistema'))
            // Un bloqueo sin finalidad alcanza a todas.
            ->where(fn ($q) => $q->whereNull('finalidad_id')
                ->when($finalidad, fn ($q) => $q->orWhere('finalidad_id', $finalidad->getKey())))
            ->exists();
    }

    /**
     * Si hay un bloqueo vigente de este sistema que impida CORREGIR el dato del
     * titular, que no es la misma pregunta que `vigente()`.
     *
     * ## El bloqueo que se muerde la cola
     *
     * Registrar una rectificación pone un bloqueo preventivo SIN finalidad, o
     * sea sobre todas. Un adoptante que consulte `vigente()` honestamente antes
     * de propagar la corrección al maestro de personas se frena a sí mismo el
     * cumplimiento del derecho que está tramitando: el bloqueo que existe para
     * proteger al titular mientras su dato está en disputa termina impidiendo
     * que el dato se arregle. `discapacidad-graneros` lo descubrió construyendo
     * su primer candado real y lo resolvió con una excepción a mano en su lado;
     * el módulo no lo advertía en ninguna parte, así que el próximo adoptante lo
     * iba a descubrir en producción o —peor— no lo iba a descubrir.
     *
     * Lo que distingue a las dos preguntas: suspender el tratamiento significa
     * **no usar** el dato en disputa (no exhibirlo, no exportarlo, no cruzarlo,
     * no decidir con él). Corregirlo no es usarlo: es la vía por la que la
     * disputa termina. Por eso el bloqueo preventivo de una rectificación en
     * trámite es lo único que este método exceptúa.
     *
     * La excepción es angosta a propósito, y conviene leer qué NO exceptúa:
     *
     * - El bloqueo de una **oposición** en trámite. Corregir el dato no es el
     *   ejercicio del derecho que esa oposición puso en marcha.
     * - El bloqueo **definitivo** de una oposición o una supresión acogidas. Ahí
     *   el municipio resolvió que deja de tratar el dato, y escribir sobre él no
     *   es cumplir nada.
     * - Un bloqueo **sin solicitud** —puesto a mano por un funcionario—. El
     *   módulo no tiene con qué saber que esa suspensión admita correcciones.
     * - El bloqueo de una rectificación **ya resuelta** que siguiera vigente: sin
     *   trámite abierto no hay derecho en curso que la corrección esté cumpliendo.
     *
     * Lo que este método NO consigue, igual que `vigente()`: que nada corrija el
     * dato. Es una consulta; quien la haga antes de escribir es el adoptante.
     */
    public function impideCorregir(Model $titular, ?Finalidad $finalidad = null): bool
    {
        return $this->deEsteTitular($titular)
            ->delSistema((string) config('privacidad.sistema'))
            ->where(fn ($q) => $q->whereNull('finalidad_id')
                ->when($finalidad, fn ($q) => $q->orWhere('finalidad_id', $finalidad->getKey())))
            // Un bloqueo sin solicitud, o con una que no es una rectificación
            // abierta, sí impide: `whereDoesntHave` da verdadero para los dos.
            ->whereDoesntHave('solicitud', fn ($q) => $q
                ->where('tipo', TipoDeSolicitud::Rectificacion->value)
                ->whereIn('estado', array_map(
                    fn (EstadoDeSolicitud $estado): string => $estado->value,
                    EstadoDeSolicitud::pendientes(),
                )))
            ->exists();
    }

    /**
     * En qué sistemas del ecosistema hay hoy una suspensión vigente sobre este
     * titular, ESTE INCLUIDO.
     *
     * Es la contraparte de haber angostado `vigente()` a un solo sistema: sin
     * esto, «el vecino ya se opuso en otra ventanilla» sería un hecho escrito en
     * la base que ningún sistema puede ver, y el riesgo de cesar de menos —o
     * sea, de incumplir un derecho ya ejercido— quedaría silencioso. Con esto,
     * un panel puede mostrarle al funcionario que el titular ejerció el derecho
     * en otro sistema para que alguien decida qué corresponde acá.
     *
     * Lo que devuelve NO es una respuesta a «¿puedo tratar el dato?»: para eso
     * está `vigente()`. Es informativo y a propósito grueso —no distingue por
     * finalidad—, porque las finalidades de otro sistema son de su RAT y este no
     * las puede interpretar.
     *
     * @return array<int, string> nombres de sistema, ordenados y sin repetir
     */
    public function sistemasConBloqueoVigente(Model $titular): array
    {
        return $this->deEsteTitular($titular)
            ->orderBy('sistema')
            ->distinct()
            ->pluck('sistema')
            ->all();
    }

    /**
     * `titular_id` es varchar(64) (admite RUT, UUID o un id numérico). Ligar
     * `getKey()` tal cual —un `int` de PHP para un titular con clave
     * autoincremental— hace que MariaDB compare la columna como número: no
     * puede usar el índice `(titular_type, titular_id, levantado_en)` por la
     * segunda columna y termina barriendo todas las filas de ese
     * `titular_type`. Ligado como string entra por el índice completo.
     *
     * @return Builder<Bloqueo>
     */
    private function deEsteTitular(Model $titular)
    {
        return Bloqueo::query()
            ->where('titular_type', $titular->getMorphClass())
            ->where('titular_id', (string) $titular->getKey())
            ->vigentes();
    }
}
