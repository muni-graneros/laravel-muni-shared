<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Throwable;

/**
 * Aplicar una rectificación solo en el sistema local es peor que no aplicarla:
 * la siguiente sincronización con el maestro la pisa, y para entonces el
 * municipio ya certificó por escrito que el dato quedó corregido.
 */
class Rectificaciones
{
    public function __construct(
        private readonly Solicitudes $solicitudes,
        private readonly RegistroDeEvidencia $evidencia,
    ) {}

    /** @param array<string, mixed> $cambios */
    public function aplicar(Solicitud $solicitud, array $cambios, string $fundamento): void
    {
        // Estas tres son fallas de lo que pidió quien llama, no de la propagación:
        // van como ResolucionInvalida —el tipo que el módulo ya usa para
        // "esta solicitud no se puede resolver así"— y no como
        // RectificacionNoPropagada, que significa una sola cosa muy concreta:
        // el maestro de personas no aceptó el cambio. Quien atrape esa clase
        // para reaccionar al rechazo del maestro no puede recibir además los
        // formularios mal armados.
        //
        // Dos formas de acoger una rectificación que nunca ocurrió: resolver
        // como rectificada una solicitud que pedía otra cosa (una supresión
        // queda "acogida" sin haberse suprimido nada), o acoger una lista de
        // cambios vacía, que sella la solicitud y certifica una corrección que
        // no tocó ningún dato. Las dos terminan en el mismo lugar: un registro
        // que dice que el municipio corrigió algo que sigue igual.
        if ($solicitud->tipo !== TipoDeSolicitud::Rectificacion) {
            throw new ResolucionInvalida(
                "La solicitud #{$solicitud->getKey()} es de tipo «{$solicitud->tipo->etiqueta()}»: "
                .'solo una solicitud de rectificación se resuelve rectificando.',
            );
        }

        if ($cambios === []) {
            throw new ResolucionInvalida(
                "La solicitud #{$solicitud->getKey()} no trae ningún cambio que aplicar: "
                .'acogerla certificaría una corrección que no se hizo.',
            );
        }

        // El mismo requisito que exige Solicitudes::resolver(), pero comprobado
        // ACÁ y antes de tomar(): si se dejara llegar hasta acoger(), la
        // excepción saldría desde dentro de la transacción y el catch de abajo
        // la archivaría como una falla de la rectificación, cuando lo único que
        // pasó es que el formulario iba sin fundamento.
        if (trim($fundamento) === '') {
            throw new ResolucionInvalida('Toda resolución debe ir fundada: es lo que se le responde al titular.');
        }

        $titular = $solicitud->titular;

        // Se exige TitularDeDatos, no solo Model: sin camposRectificables()
        // no hay forma de saber qué es lícito corregir.
        if (! $titular instanceof TitularDeDatos) {
            throw new RectificacionNoPropagada(
                "La solicitud #{$solicitud->getKey()} no tiene un titular vigente al que rectificar.",
            );
        }

        // El derecho de rectificación cubre los datos inexactos del propio
        // titular, no cualquier columna del modelo. Se valida ANTES de tomar()
        // para que una solicitud malformada ni siquiera mueva el estado.
        $camposNoPermitidos = array_diff(array_keys($cambios), $titular->camposRectificables());

        if ($camposNoPermitidos !== []) {
            throw new RectificacionNoPropagada(
                'No se puede rectificar «'.implode('», «', $camposNoPermitidos)
                .'»: no está(n) entre los campos que el titular puede corregir de su propio registro.',
            );
        }

        // Fuera de la transacción a propósito: si el maestro rechaza el cambio,
        // el rollback deshace la edición local pero la solicitud debe seguir
        // viéndose "en trámite", no volver a "recibida" como si nada hubiera
        // pasado. Un operador tiene que poder ver que se intentó y falló.
        $this->solicitudes->tomar($solicitud);

        try {
            DB::transaction(function () use ($titular, $cambios, $solicitud, $fundamento): void {
                $titular->forceFill($cambios)->save();

                $propagacion = $this->propagar($titular, $cambios);

                if ($propagacion->laRechazoElMaestro()) {
                    // El rollback deja el dato viejo, que es honesto: el municipio no
                    // puede certificar una corrección que el maestro no aceptó.
                    throw new RectificacionNoPropagada(
                        'El maestro de personas rechazó la rectificación. La solicitud queda en trámite.',
                    );
                }

                $this->solicitudes->acoger($solicitud, $fundamento);

                // Se registran los NOMBRES de los campos, nunca sus valores: la
                // bitácora no está cifrada, y volcar ahí el dato viejo/nuevo del
                // titular duplicaría exactamente la información personal que este
                // módulo existe para minimizar.
                //
                // Y qué pasó con el maestro, que antes no quedaba escrito: con
                // `propagar()` devolviendo `bool`, esta fila decía lo mismo
                // cuando el maestro había aceptado el cambio y cuando nadie
                // había hablado con él. Son dos afirmaciones distintas sobre el
                // mismo derecho y la segunda es la que una fiscalización
                // pregunta.
                $this->evidencia->registrar('rectificacion.aplicada', [
                    'solicitud_id' => $solicitud->getKey(),
                    'campos' => array_keys($cambios),
                    'propagacion' => $propagacion->paraEvidencia(),
                ], $titular);
            });
        } catch (Throwable $e) {
            // Se atrapa Throwable y no solo RectificacionNoPropagada porque el
            // rechazo del maestro casi nunca llega como `false`: el transporte
            // del ecosistema (SincronizarAlMaestro) hace `$resp->throw()` ante
            // cualquier respuesta no exitosa. Mirando solo la excepción propia,
            // ni los refresh() ni la evidencia correrían justamente en el caso
            // más frecuente en producción.
            //
            // Todo el rescate va en un solo try: pase lo que pase acá adentro,
            // lo que tiene que salir de este método es $e. Antes el registro de
            // evidencia quedaba fuera del guard y, con la conexión caída,
            // reemplazaba a la excepción original —exactamente lo que el guard
            // existía para impedir—.
            try {
                // Primero la evidencia, que es lo único irrecuperable: los dos
                // refresh() de abajo solo arreglan objetos en memoria de una
                // request que ya se está muriendo.
                //
                // El evento NO se llama "rechazada_por_maestro": desde acá se ve
                // cualquier falla de la transacción —un choque de constraint, la
                // caída del registro de evidencia local— y archivar todas como
                // un rechazo del maestro sería escribir en la bitácora algo que
                // no pasó. Peor aún cuando propagar() devolvió true: ahí el
                // maestro SÍ aceptó y tiene el dato corregido, y el registro
                // legal diría lo contrario. Se guarda la clase de la excepción,
                // nunca su mensaje: un mensaje puede arrastrar el dato personal
                // que este módulo existe para no duplicar.
                $this->evidencia->registrar('rectificacion.fallida', [
                    'solicitud_id' => $solicitud->getKey(),
                    'campos' => array_keys($cambios),
                    'causa' => $e::class,
                    'rechazada_por_maestro' => $e instanceof RectificacionNoPropagada,
                ], $titular);

                // El rollback revierte las filas, pero no las instancias en memoria
                // que ya se mutaron: sin estos refresh(), un caller que haya
                // eager-cargado la relación (p. ej. una pantalla de revisión con
                // Solicitud::with('titular')) seguiría leyendo el valor rechazado,
                // y la solicitud seguiría diciendo "acogida" cuando en la base
                // volvió a "en trámite".
                $titular->refresh();
                $solicitud->refresh();
            } catch (Throwable) {
                // Si la conexión murió, el rescate también falla. Su error no
                // puede tapar el que explica por qué no se rectificó.
            }

            // Se relanza tal cual, sin envolverla en RectificacionNoPropagada:
            // un timeout del maestro y una lista blanca violada son problemas
            // distintos, y quien atiende el mesón necesita distinguirlos.
            throw $e;
        }
    }

    /** @param array<string, mixed> $cambios */
    private function propagar(TitularDeDatos $titular, array $cambios): ResultadoDePropagacion
    {
        // Un sistema que no es modelo de lectura del maestro no enlaza el
        // contrato: para él la rectificación local es la definitiva. Eso es
        // «no correspondía propagar» y no un éxito, aunque el efecto sobre la
        // transacción sea el mismo: la fila de evidencia tiene que poder
        // distinguir un sistema sin maestro de uno que propagó de verdad, sin
        // que haya que ir a averiguar qué tenía enlazado ese sistema aquel día.
        if (! app()->bound(PropagaRectificacion::class)) {
            return ResultadoDePropagacion::noCorrespondia(
                'Este sistema no enlazó Contratos\PropagaRectificacion: no es modelo de lectura '
                .'del maestro de personas y la rectificación local es la definitiva.',
            );
        }

        return app(PropagaRectificacion::class)->propagar($titular, $cambios);
    }
}
