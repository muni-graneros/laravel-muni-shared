<?php

namespace Muni\Shared\Privacidad;

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

    /** @return int cuántos bloqueos quedaron levantados */
    public function levantarPorSolicitud(Solicitud $solicitud): int
    {
        return DB::transaction(function () use ($solicitud): int {
            $afectados = Bloqueo::query()
                ->where('solicitud_id', $solicitud->getKey())
                ->vigentes()
                ->update(['levantado_en' => now()]);

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
     * Si hay un bloqueo vigente que alcance a la finalidad dada (o uno sin
     * finalidad, que alcanza a todas). Solo consulta: no impide nada por sí
     * misma, ver el docblock de la clase.
     */
    public function vigente(Model $titular, ?Finalidad $finalidad = null): bool
    {
        return Bloqueo::query()
            ->where('titular_type', $titular->getMorphClass())
            ->where('titular_id', $titular->getKey())
            ->vigentes()
            // Un bloqueo sin finalidad alcanza a todas.
            ->where(fn ($q) => $q->whereNull('finalidad_id')
                ->when($finalidad, fn ($q) => $q->orWhere('finalidad_id', $finalidad->getKey())))
            ->exists();
    }
}
