<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Throwable;

/**
 * El derecho de supresión ejercido POR EL TITULAR, que no es la supresión por
 * vencimiento del plazo (`AplicarRetencion`).
 *
 * Existe porque faltaba la pieza y su ausencia producía el peor estado posible:
 * el titular pedía que borraran sus datos, el funcionario acogía la solicitud
 * con `Solicitudes::acoger()` —que sella la fila como resuelta— y los datos
 * seguían enteros. Un registro que certifica por escrito un cumplimiento que no
 * ocurrió es peor que no haber tramitado nada: la solicitud queda cerrada, el
 * plazo legal deja de correr y nadie vuelve a mirarla. Es el mismo defecto
 * contra el que `Rectificaciones` protege, entrando por la puerta de al lado.
 *
 * ## Por qué acoger una supresión NO puede ser incondicional
 *
 * La supresión por retención es unilateral: venció el plazo que el propio
 * municipio declaró, y no hay nada que discutir. La supresión a petición no lo
 * es. Cuando el municipio trata el dato por **función legal con norma
 * habilitante** —no por consentimiento— el derecho de supresión no procede
 * sobre esa finalidad mientras el plazo de conservación siga corriendo:
 * borrarlo sería incumplir la norma que obliga a conservarlo. Una supresión
 * total incondicional destruiría justo lo que la ley manda guardar, y lo haría
 * en nombre de la ley.
 *
 * Por eso este servicio tiene tres desenlaces y no uno:
 *
 * 1. **Procede completa.** Ninguna finalidad activa obliga a conservar: se
 *    destruye —purga de sensibles, anonimización, corte del vínculo con todas
 *    las tablas del módulo, borrado de los documentos en disco— y se acoge.
 * 2. **Procede en parte.** Alguna finalidad obliga a conservar y alguna no: NO
 *    se destruye nada y se acoge PARCIALMENTE, dejando un bloqueo definitivo
 *    por cada finalidad en que el derecho sí procede. La vía correcta ahí es el
 *    cese del tratamiento, no el borrado: el módulo no sabe —no puede saber—
 *    qué columna del sistema adoptante pertenece a qué finalidad, así que
 *    borrar "lo de la finalidad que cesa" sería adivinar sobre datos que la
 *    norma manda conservar. Lo que sí puede hacer, y hace, es registrar que ese
 *    tratamiento se detiene.
 * 3. **No procede.** Todas las finalidades obligan a conservar: `aplicar()`
 *    truena con `SupresionNoProcede`, cuyo mensaje trae la norma y el plazo, y
 *    NO resuelve la solicitud. Rechazarla es una resolución fundada que firma
 *    una persona.
 *
 * ## Lo que este servicio NO garantiza
 *
 * - **Que el tratamiento cese de verdad en la acogida parcial.** Deja bloqueos;
 *   consultarlos antes de tratar el dato es obligación del sistema adoptante
 *   (ver el docblock de `Bloqueos`). El módulo no tiene scope global ni
 *   interceptor de consultas.
 * - **Que la norma citada obligue efectivamente a conservar.** Lo que comprueba
 *   es lo que el RAT declara: base de licitud que exige norma, norma escrita, y
 *   plazo declarado que todavía corre para este titular según el resolvedor del
 *   adoptante. La lectura jurídica la hace quien resuelve.
 * - **Que la destrucción sea irreversible en el maestro federado.** Eso depende
 *   de qué haga el maestro con la baja (ver el pendiente BLOQUEANTE del spec de
 *   pendientes: hoy el maestro OCULTA al suprimido en vez de suprimirlo).
 */
class Supresiones
{
    public function __construct(
        private readonly Solicitudes $solicitudes,
        private readonly Bloqueos $bloqueos,
        private readonly Bitacora $bitacora,
        private readonly RegistroDeEvidencia $evidencia,
        private readonly ResuelveTitularesVencidos $resolvedor,
        private readonly SupresionEnElMaestro $maestro,
    ) {}

    /**
     * Hasta dónde alcanza el derecho de supresión de este titular, sin tocar
     * nada.
     *
     * Una finalidad obliga a conservar cuando cumple las TRES:
     *
     * 1. su base de licitud exige norma habilitante (función legal u obligación
     *    legal): las demás bases no fundan ninguna obligación de conservar
     *    frente al titular —el consentimiento se retira, y con él la finalidad—;
     * 2. declara un plazo de retención: sin plazo no hay fecha en que deje de
     *    necesitarse, y ver más abajo por qué eso NO se lee como "para siempre";
     * 3. ese plazo todavía corre para ESTE titular, que es lo único que el
     *    módulo no puede saber por su cuenta: se lo pregunta al sistema
     *    adoptante por el mismo contrato que usa la retención
     *    (`ResuelveTitularesVencidos`), porque solo él sabe desde cuándo trata a
     *    cada persona.
     *
     * Dos consecuencias que conviene leer antes de confiar en el resultado:
     *
     * - Con el enlace por defecto (`NingunTitularVencido`) nadie está nunca
     *   vencido, así que toda finalidad por función legal con plazo impide. Es
     *   la dirección segura para equivocarse —se conserva de más, y el titular
     *   puede reclamar— pero significa que un sistema que no implementó el
     *   resolvedor no va a poder suprimir a nadie a petición.
     * - Una finalidad **por función legal SIN plazo declarado no impide**, y no
     *   es un olvido: `plazo_retencion_meses` en null es ambiguo en el RAT —dice
     *   tanto "no retiene" (el caso de `sincronizacion_maestro`) como "se
     *   conserva indefinidamente"— y `AplicarRetencion` ya la lee como "no
     *   conserva a nadie". Leerla acá al revés dejaría al módulo negando una
     *   supresión a petición por una finalidad que su propio barrido por cron
     *   ignora al destruir a la misma persona. La ambigüedad es del esquema y
     *   está anotada como pendiente; lo que no se puede es tener dos servicios
     *   que la resuelvan distinto.
     *
     * Costo: recorre la lista de vencidos de cada finalidad buscando a este
     * titular. Es O(vencidos) por finalidad y corre una vez por solicitud
     * atendida en el mesón, no por lote; en un padrón grande con muchas
     * finalidades se va a notar, y ahí conviene que el resolvedor del adoptante
     * devuelva un iterador perezoso y no una colección entera.
     */
    public function evaluar(TitularDeDatos $titular): EvaluacionDeSupresion
    {
        $impiden = [];
        $cesan = [];

        $finalidades = Finalidad::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('activa', true)
            ->get();

        foreach ($finalidades as $finalidad) {
            if ($this->obligaAConservar($finalidad, $titular)) {
                $impiden[] = $finalidad;
            } else {
                $cesan[] = $finalidad;
            }
        }

        return new EvaluacionDeSupresion($impiden, $cesan);
    }

    /**
     * Resuelve una solicitud de supresión SUPRIMIENDO, hasta donde la ley deja.
     *
     * El orden es el mismo que el de la retención y por el mismo motivo: primero
     * el maestro de personas y después lo local. Un rechazo del maestro se
     * arregla reintentando; un archivo borrado del disco no lo devuelve ningún
     * rollback. El peor caso queda siendo un titular suprimido en el maestro y
     * todavía completo acá.
     *
     * `tomar()` va antes de todo lo destructivo y fuera de la transacción, como
     * en `Rectificaciones`: si el maestro rechaza, la solicitud tiene que
     * seguir viéndose "en trámite" y no volver a "recibida" como si nadie la
     * hubiera mirado. Un funcionario tiene que poder ver que se intentó y falló.
     *
     * @param  ?string  $respuestaPath  el documento de respuesta al titular. En
     *                                  la supresión total se escribe y se borra
     *                                  en la misma operación, a propósito: es un
     *                                  documento con los datos de quien pidió
     *                                  que los borraran, y conservarlo
     *                                  contradiría la supresión. Entregárselo al
     *                                  titular es del procedimiento, no del
     *                                  archivo.
     *
     * @throws ResolucionInvalida si la solicitud no es de supresión, ya está
     *                            resuelta, va sin fundamento, o su titular ya
     *                            no está
     * @throws SupresionNoProcede si ninguna finalidad admite la supresión
     * @throws SupresionNoPropagada si falta la declaración sobre el maestro o el
     *                              maestro rechaza la supresión
     * @throws ArchivoNoSuprimido si el disco no confirma el borrado de un
     *                            documento: la supresión entera se revierte
     */
    public function aplicar(Solicitud $solicitud, string $fundamento, ?string $respuestaPath = null): ResultadoDeSupresion
    {
        if ($solicitud->tipo !== TipoDeSolicitud::Supresion) {
            throw new ResolucionInvalida(
                "La solicitud #{$solicitud->getKey()} es de tipo «{$solicitud->tipo->etiqueta()}»: "
                .'solo una solicitud de supresión se resuelve suprimiendo.',
            );
        }

        // Igual que en Rectificaciones: se comprueba ACÁ y antes de tomar(), o
        // la excepción saldría desde dentro y el rescate la archivaría como una
        // falla de la supresión cuando lo único que pasó es que el formulario
        // iba sin fundamento.
        if (trim($fundamento) === '') {
            throw new ResolucionInvalida('Toda resolución debe ir fundada: es lo que se le responde al titular.');
        }

        $titular = $solicitud->titular;

        if (! $titular instanceof TitularDeDatos || ! $titular instanceof Model) {
            throw new ResolucionInvalida(
                "La solicitud #{$solicitud->getKey()} no tiene un titular vigente al que suprimir. "
                .'Una fila sin titular es una solicitud cuyo titular ya se anonimizó: el hecho queda '
                .'como evidencia, pero no hay datos que borrar.',
            );
        }

        // Antes de mover el estado: si el sistema no declaró qué pasa en el
        // registro federado, esta solicitud no se puede tramitar en absoluto.
        $this->maestro->exigirDeclaracion();

        $this->solicitudes->tomar($solicitud);

        $evaluacion = $this->evaluar($titular);

        if ($evaluacion->noProcede()) {
            // La solicitud queda EN TRÁMITE, no rechazada: quien tiene que
            // responderle al titular es el municipio, con una resolución
            // fundada que cite esta misma norma. Ver SupresionNoProcede.
            throw new SupresionNoProcede($evaluacion->explicacion());
        }

        return $evaluacion->procedeTotal()
            ? $this->suprimirTodo($solicitud, $titular, $evaluacion, $fundamento, $respuestaPath)
            : $this->cesarLoQueProcede($solicitud, $titular, $evaluacion, $fundamento, $respuestaPath);
    }

    private function suprimirTodo(
        Solicitud $solicitud,
        Model&TitularDeDatos $titular,
        EvaluacionDeSupresion $evaluacion,
        string $fundamento,
        ?string $respuestaPath,
    ): ResultadoDeSupresion {
        // Se lee ANTES de tocar nada: después de anonimizar no queda con qué
        // identificar a esta persona en el maestro.
        $documento = $titular->titularDocumento();

        try {
            // Dentro del try, y esta es la falla que más importa dejar
            // registrada: «se pidió la supresión, se intentó, y el registro
            // federado la rechazó» es exactamente lo que hay que poder mostrar
            // cuando el titular pregunte por qué sus datos siguen ahí.
            $this->maestro->propagar($titular, $documento);

            // La marca de proceso que consulta el observador `saved` del sistema
            // adoptante para NO empujar al maestro lo que se escribe acá: sin
            // ella, anonimizar() da de alta en el registro central a quien se
            // acaba de suprimir.
            $barrido = SupresionEnCurso::durante(fn (): ResultadoDesvinculacion => DB::transaction(
                function () use ($solicitud, $titular, $evaluacion, $fundamento, $respuestaPath): ResultadoDesvinculacion {
                    // La resolución se sella ANTES del barrido, y no después,
                    // para que el barrido la alcance: el fundamento es la
                    // respuesta escrita al titular y puede llevar su nombre, su
                    // RUT o su diagnóstico. Sellarla después dejaría ese texto
                    // en una fila que el resto de la anonimización ya dejó
                    // huérfana — anonimización a medias, y de la peor clase:
                    // la que se ve completa. Lo que sobrevive es el hecho: la
                    // solicitud figura acogida, con su fecha y su tipo.
                    $this->solicitudes->acoger($solicitud, $fundamento, $respuestaPath);

                    // El orden importa, igual que en la retención: primero lo
                    // sensible, después la anonimización. Al revés, el registro
                    // anonimizado podría conservar el archivo sensible sin nadie
                    // a quien asociarlo para borrarlo después.
                    $titular->purgarDatosSensibles();
                    $titular->anonimizar();

                    // Antes de desvincular, para que esta misma entrada quede
                    // barrida. Registrada después, sería una fila con titular_id
                    // vivo al lado de las que dicen titular_ref, en el mismo
                    // instante: el join de dos saltos que la anonimización
                    // existe para cortar.
                    $this->evidencia->registrar('supresion.aplicada', [
                        'solicitud_id' => $solicitud->getKey(),
                        'finalidades' => $evaluacion->codigosQueCesan(),
                    ], $titular);

                    return $this->bitacora->desvincular($titular);
                },
            ));
        } catch (Throwable $e) {
            $this->rescatar($e, $solicitud, $titular, total: true);

            throw $e;
        }

        // El objeto en memoria todavía dice lo que decía antes del barrido: sin
        // esto, un panel que ya tenía cargada la solicitud le mostraría al
        // funcionario el fundamento que la base acaba de suprimir.
        $titular->refresh();
        $solicitud->refresh();

        return new ResultadoDeSupresion(total: true, evaluacion: $evaluacion, barrido: $barrido);
    }

    /**
     * Acogida parcial: cesa el tratamiento en las finalidades donde el derecho
     * procede, y NO se destruye nada.
     *
     * El bloqueo queda ligado a esta solicitud —así se sabe qué lo originó— y
     * sobrevive a su resolución: `Solicitudes::resolver()` solo levanta los
     * bloqueos cuando acoger reanuda el tratamiento, que para la supresión es
     * justamente lo contrario.
     */
    private function cesarLoQueProcede(
        Solicitud $solicitud,
        Model&TitularDeDatos $titular,
        EvaluacionDeSupresion $evaluacion,
        string $fundamento,
        ?string $respuestaPath,
    ): ResultadoDeSupresion {
        try {
            DB::transaction(function () use ($solicitud, $titular, $evaluacion, $fundamento, $respuestaPath): void {
                foreach ($evaluacion->cesan as $finalidad) {
                    $this->bloqueos->bloquear(
                        $titular,
                        $finalidad,
                        "Supresión acogida parcialmente (solicitud #{$solicitud->getKey()}): "
                        ."cesa el tratamiento para «{$finalidad->codigo}».",
                        $solicitud,
                    );
                }

                $this->solicitudes->acogerParcialmente($solicitud, $fundamento, $respuestaPath);

                // Las NORMAS quedan escritas en la evidencia, no solo los
                // códigos: dentro de un año, «registro_comunal» no le dice a
                // nadie por qué no se suprimió, y el RAT puede haber cambiado.
                $this->evidencia->registrar('supresion.parcial', [
                    'solicitud_id' => $solicitud->getKey(),
                    'impiden' => $evaluacion->normasQueImpiden(),
                    'cesan' => $evaluacion->codigosQueCesan(),
                ], $titular);
            });
        } catch (Throwable $e) {
            $this->rescatar($e, $solicitud, $titular, total: false);

            throw $e;
        }

        return new ResultadoDeSupresion(
            total: false,
            evaluacion: $evaluacion,
            finalidadesCesadas: count($evaluacion->cesan),
        );
    }

    /**
     * Deja constancia de que la supresión falló y devuelve los objetos en
     * memoria a lo que dice la base.
     *
     * Todo el rescate va dentro de su propio try, como en `Rectificaciones`:
     * pase lo que pase acá adentro, lo que tiene que salir del método que llama
     * es la excepción original. Un rescate que revienta —la conexión caída— y
     * reemplaza a la excepción que explica por qué no se suprimió es justo lo
     * que el guard existe para impedir.
     *
     * Se guarda la CLASE de la excepción y nunca su mensaje: un mensaje puede
     * arrastrar el dato personal que este módulo existe para no duplicar.
     */
    private function rescatar(Throwable $e, Solicitud $solicitud, Model $titular, bool $total): void
    {
        try {
            $this->evidencia->registrar('supresion.fallida', [
                'solicitud_id' => $solicitud->getKey(),
                'causa' => $e::class,
                'total' => $total,
                'rechazada_por_maestro' => $e instanceof SupresionNoPropagada,
            ], $titular);

            $titular->refresh();
            $solicitud->refresh();
        } catch (Throwable) {
            // Si la conexión murió, el rescate también falla. Su error no puede
            // tapar el que explica por qué no se suprimió.
        }
    }

    /**
     * Si esta finalidad obliga a conservar los datos de este titular pese a su
     * solicitud. Ver el docblock de `evaluar()` para las tres condiciones y sus
     * consecuencias.
     */
    private function obligaAConservar(Finalidad $finalidad, TitularDeDatos $titular): bool
    {
        if (! $finalidad->base_licitud->exigeNormaHabilitante()) {
            return false;
        }

        if ($finalidad->plazo_retencion_meses === null) {
            return false;
        }

        return ! $this->plazoVencido($finalidad, $titular);
    }

    private function plazoVencido(Finalidad $finalidad, TitularDeDatos $titular): bool
    {
        $buscado = $this->clave($titular);

        foreach ($this->resolvedor->vencidos($finalidad) as $vencido) {
            if ($this->clave($vencido) === $buscado) {
                return true;
            }
        }

        return false;
    }

    /**
     * La misma identidad estable que usa `AplicarRetencion` entre sus dos
     * pasadas: morph + clave para un Model, y el documento para un titular que
     * no lo es (queda en memoria, no se persiste en ninguna parte).
     */
    private function clave(TitularDeDatos $titular): string
    {
        return $titular instanceof Model
            ? $titular->getMorphClass().'#'.$titular->getKey()
            : $titular::class.'#'.$titular->titularDocumento();
    }
}
