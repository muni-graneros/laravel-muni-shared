<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * La ley pide suprimir cuando el dato ya no es necesario para la finalidad.
 * Acá eso son dos cosas distintas: los sensibles se borran de verdad y el
 * registro se anonimiza, para no perder la serie estadística comunal.
 */
class AplicarRetencion
{
    public function __construct(
        private readonly RegistroDeEvidencia $evidencia,
        private readonly ResuelveTitularesVencidos $resolvedor,
        private readonly Bitacora $bitacora,
    ) {}

    /**
     * @return array<int, array{finalidad: string, titulares: int}>
     */
    public function ejecutar(bool $simulacion = true): array
    {
        $resumen = [];

        $finalidades = Finalidad::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('activa', true)
            ->whereNotNull('plazo_retencion_meses')
            ->get();

        foreach ($finalidades as $finalidad) {
            $contados = 0;

            foreach ($this->resolvedor->vencidos($finalidad) as $titular) {
                $contados++;

                if ($simulacion) {
                    continue;
                }

                $this->aplicarA($titular, $finalidad);
            }

            if ($contados > 0) {
                $resumen[] = ['finalidad' => (string) $finalidad->codigo, 'titulares' => $contados];
            }
        }

        return $resumen;
    }

    private function aplicarA(TitularDeDatos $titular, Finalidad $finalidad): void
    {
        // El orden importa: primero se borra lo sensible, después se anonimiza.
        // Al revés, el registro anonimizado podría conservar el archivo sensible
        // sin nadie a quien asociarlo para borrarlo después.
        //
        // La transacción cubre las dos escrituras de fila y la evidencia: si
        // anonimizar() o el registro de evidencia fallan, ambas se revierten y
        // no queda un dato purgado sin bitácora que pruebe que la supresión fue
        // lícita. Lo que la transacción NO cubre son los archivos en disco que
        // purgarDatosSensibles() borra: un rollback de base de datos no
        // restaura un archivo eliminado. Por eso el orden sigue siendo
        // load-bearing incluso con la transacción: si algo falla después de
        // purgar, el archivo ya no está aunque la fila vuelva a su estado
        // anterior.
        DB::transaction(function () use ($titular, $finalidad): void {
            $titular->purgarDatosSensibles();
            $titular->anonimizar();

            // Después de anonimizar y antes de la entrada de evidencia: al revés,
            // esa entrada quedaría huérfana también y se perdería la traza de que
            // la retención se aplicó a alguien.
            if ($titular instanceof Model) {
                $this->bitacora->desvincular($titular);
            }

            $this->evidencia->registrar('retencion.aplicada', [
                'finalidad' => $finalidad->codigo,
                'plazo_meses' => $finalidad->plazo_retencion_meses,
            ], $titular instanceof Model ? $titular : null);
        });
    }
}
