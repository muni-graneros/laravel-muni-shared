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
        // Dos formas de acoger una rectificación que nunca ocurrió: resolver
        // como rectificada una solicitud que pedía otra cosa (una supresión
        // queda "acogida" sin haberse suprimido nada), o acoger una lista de
        // cambios vacía, que sella la solicitud y certifica una corrección que
        // no tocó ningún dato. Las dos terminan en el mismo lugar: un registro
        // que dice que el municipio corrigió algo que sigue igual.
        if ($solicitud->tipo !== TipoDeSolicitud::Rectificacion) {
            throw new RectificacionNoPropagada(
                "La solicitud #{$solicitud->getKey()} es de tipo «{$solicitud->tipo->etiqueta()}»: "
                .'solo una solicitud de rectificación se resuelve rectificando.',
            );
        }

        if ($cambios === []) {
            throw new RectificacionNoPropagada(
                "La solicitud #{$solicitud->getKey()} no trae ningún cambio que aplicar: "
                .'acogerla certificaría una corrección que no se hizo.',
            );
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

                if ($this->propagacionRechazada($titular, $cambios)) {
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
                $this->evidencia->registrar('rectificacion.aplicada', [
                    'solicitud_id' => $solicitud->getKey(),
                    'campos' => array_keys($cambios),
                ], $titular);
            });
        } catch (Throwable $e) {
            // Se atrapa Throwable y no solo RectificacionNoPropagada porque el
            // rechazo del maestro casi nunca llega como `false`: el transporte
            // del ecosistema (SincronizarAlMaestro) hace `$resp->throw()` ante
            // cualquier respuesta no exitosa. Mirando solo la excepción propia,
            // ni el refresh() ni la evidencia del rechazo correrían justamente
            // en el caso más frecuente en producción.
            try {
                // El rollback revierte la fila, pero no la instancia en memoria que
                // forceFill() ya mutó: sin este refresh(), un caller que haya
                // eager-cargado la relación (p. ej. una pantalla de revisión con
                // Solicitud::with('titular')) seguiría leyendo el valor rechazado.
                $titular->refresh();
            } catch (Throwable) {
                // Si la conexión murió, el refresh también falla. Su error no
                // puede tapar el que explica por qué no se rectificó: el titular
                // en memoria queda sucio, pero la causa real sigue viajando.
            }

            // Fuera de la transacción que hizo rollback: si quedara adentro,
            // el propio registro de evidencia del rechazo desaparecería con
            // ella, y este es justamente el caso que no puede quedar sin rastro.
            $this->evidencia->registrar('rectificacion.rechazada_por_maestro', [
                'solicitud_id' => $solicitud->getKey(),
                'campos' => array_keys($cambios),
            ], $titular);

            // Se relanza tal cual, sin envolverla en RectificacionNoPropagada:
            // un timeout del maestro y una lista blanca violada son problemas
            // distintos, y quien atiende el mesón necesita distinguirlos.
            throw $e;
        }
    }

    /** @param array<string, mixed> $cambios */
    private function propagacionRechazada(TitularDeDatos $titular, array $cambios): bool
    {
        // Un sistema que no es modelo de lectura del maestro no enlaza el
        // contrato: para él la rectificación local es la definitiva.
        if (! app()->bound(PropagaRectificacion::class)) {
            return false;
        }

        return ! app(PropagaRectificacion::class)->propagar($titular, $cambios);
    }
}
