<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;
use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * Aplicar una rectificación solo en el sistema local es peor que no aplicarla:
 * la siguiente sincronización con el maestro la pisa, y para entonces el
 * municipio ya certificó por escrito que el dato quedó corregido.
 */
class Rectificaciones
{
    public function __construct(private readonly Solicitudes $solicitudes) {}

    /** @param array<string, mixed> $cambios */
    public function aplicar(Solicitud $solicitud, array $cambios, string $fundamento): void
    {
        $titular = $solicitud->titular;

        if (! $titular instanceof Model) {
            throw new RectificacionNoPropagada(
                "La solicitud #{$solicitud->getKey()} no tiene un titular vigente al que rectificar.",
            );
        }

        // Fuera de la transacción a propósito: si el maestro rechaza el cambio,
        // el rollback deshace la edición local pero la solicitud debe seguir
        // viéndose "en trámite", no volver a "recibida" como si nada hubiera
        // pasado. Un operador tiene que poder ver que se intentó y falló.
        $this->solicitudes->tomar($solicitud);

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
        });
    }

    /** @param array<string, mixed> $cambios */
    private function propagacionRechazada(Model $titular, array $cambios): bool
    {
        // Un sistema que no es modelo de lectura del maestro no enlaza el
        // contrato: para él la rectificación local es la definitiva.
        if (! app()->bound(PropagaRectificacion::class)) {
            return false;
        }

        return ! app(PropagaRectificacion::class)->propagar($titular, $cambios);
    }
}
