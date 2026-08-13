<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use RuntimeException;

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
            throw new RuntimeException(
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
}
