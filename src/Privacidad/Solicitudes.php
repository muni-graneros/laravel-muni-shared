<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Solicitud;

class Solicitudes
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    public function registrar(
        Model $titular,
        TipoDeSolicitud $tipo,
        string $detalle,
        ResultadoVerificacion $verificacion,
        string $solicitante = 'titular',
    ): Solicitud {
        // Entregar datos personales a quien no acreditó ser el titular es la
        // fuga más fácil de cometer y la más difícil de explicar después.
        if (! $verificacion->verificado) {
            throw new IdentidadNoVerificada(
                'No se puede registrar la solicitud: la identidad del solicitante no fue verificada ('
                .($verificacion->evidencia['motivo'] ?? 'sin motivo').').',
            );
        }

        $solicitud = Solicitud::create([
            'sistema' => (string) config('privacidad.sistema'),
            'titular_type' => $titular::class,
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

        return $solicitud;
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
