<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Solicitud;

class Solicitudes
{
    public function __construct(
        private readonly RegistroDeEvidencia $evidencia,
        private readonly Bloqueos $bloqueos,
    ) {}

    public function registrar(
        Model $titular,
        TipoDeSolicitud $tipo,
        string $detalle,
        ResultadoVerificacion $verificacion,
        Solicitante $solicitante = Solicitante::Titular,
    ): Solicitud {
        // Entregar datos personales a quien no acreditó ser el titular es la
        // fuga más fácil de cometer y la más difícil de explicar después.
        if (! $verificacion->verificado) {
            throw new IdentidadNoVerificada(
                'No se puede registrar la solicitud: la identidad del solicitante no fue verificada ('
                .($verificacion->evidencia['motivo'] ?? 'sin motivo').').',
            );
        }

        // Como en el resto del módulo: la fila no puede sobrevivir sin su
        // entrada de bitácora. Acá pesa doble, porque la recepción de una
        // solicitud ARCOP es lo que hace correr el plazo legal de respuesta:
        // una solicitud registrada sin evidencia de cuándo entró es exactamente
        // lo que se discute en una fiscalización.
        return DB::transaction(function () use ($titular, $tipo, $detalle, $verificacion, $solicitante) {
            $solicitud = Solicitud::create([
                'sistema' => (string) config('privacidad.sistema'),
                'titular_type' => $titular->getMorphClass(),
                'titular_id' => $titular->getKey(),
                'tipo' => $tipo,
                'estado' => EstadoDeSolicitud::Recibida,
                'recibida_en' => now(),
                'vence_en' => now()->addDays((int) config('privacidad.plazo_respuesta_dias')),
                'detalle' => $detalle,
                'verificacion_identidad' => [
                    'metodo' => $verificacion->metodo,
                    'evidencia' => $verificacion->evidencia,
                ],
                'solicitante' => $solicitante,
                'user_registro_id' => Auth::id(),
            ]);

            $this->evidencia->registrar('solicitud.registrada', [
                'solicitud_id' => $solicitud->getKey(),
                'tipo' => $tipo->value,
            ], $titular);

            // Solo rectificación y oposición: un acceso o una portabilidad no
            // ponen nada en disputa, y bloquear por ellas frenaría la atención
            // sin ninguna razón legal.
            $disputa = in_array($tipo, [TipoDeSolicitud::Rectificacion, TipoDeSolicitud::Oposicion], true);

            if ($disputa && config('privacidad.bloquear_durante_solicitud')) {
                $this->bloqueos->bloquear($titular, null, "Solicitud de {$tipo->etiqueta()} en trámite", $solicitud);
            }

            return $solicitud;
        });
    }

    public function tomar(Solicitud $solicitud): void
    {
        $this->exigirPendiente($solicitud);

        $solicitud->update(['estado' => EstadoDeSolicitud::EnTramite]);
    }

    public function acoger(Solicitud $solicitud, string $fundamento, ?string $respuestaPath = null): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::Acogida, $fundamento, $respuestaPath);
    }

    public function acogerParcialmente(Solicitud $solicitud, string $fundamento, ?string $respuestaPath = null): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::AcogidaParcial, $fundamento, $respuestaPath);
    }

    public function rechazar(Solicitud $solicitud, string $fundamento): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::Rechazada, $fundamento);
    }

    private function resolver(
        Solicitud $solicitud,
        EstadoDeSolicitud $estado,
        string $fundamento,
        ?string $respuestaPath = null,
    ): void {
        $this->exigirPendiente($solicitud);

        if (trim($fundamento) === '') {
            throw new ResolucionInvalida('Toda resolución debe ir fundada: es lo que se le responde al titular.');
        }

        // La resolución es la respuesta oficial al titular: si el registro de
        // evidencia falla, la solicitud tampoco puede quedar sellada, o el
        // sistema mostraría una solicitud resuelta sin rastro de quién la
        // resolvió ni cuándo. Rectificaciones llama a acoger() desde su propia
        // transacción; Laravel convierte esta en un savepoint y el rollback
        // externo sigue arrastrándola.
        DB::transaction(function () use ($solicitud, $estado, $fundamento, $respuestaPath): void {
            $solicitud->update([
                'estado' => $estado,
                'resuelta_en' => now(),
                'fundamento_resolucion' => $fundamento,
                'respuesta_path' => $respuestaPath,
                'user_resolucion_id' => Auth::id(),
            ]);

            $this->evidencia->registrar("solicitud.{$estado->value}", [
                'solicitud_id' => $solicitud->getKey(),
                'tipo' => $solicitud->tipo->value,
            ], $solicitud->titular);

            // Se resuelva como se resuelva —acogida, rechazada— la disputa
            // terminó: el bloqueo que la registró no tiene ya ninguna razón
            // para seguir vigente. No hace nada si nunca se bloqueó (config
            // apagada, o un tipo que no dispara bloqueo).
            $this->bloqueos->levantarPorSolicitud($solicitud);
        });
    }

    private function exigirPendiente(Solicitud $solicitud): void
    {
        if ($solicitud->estado->estaResuelta()) {
            throw new ResolucionInvalida(
                "La solicitud #{$solicitud->getKey()} ya fue resuelta el "
                .$solicitud->resuelta_en?->format('d-m-Y').'. Reabrirla falsearía el registro.',
            );
        }
    }
}
