<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
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
        $titular->purgarDatosSensibles();
        $titular->anonimizar();

        $this->evidencia->registrar('retencion.aplicada', [
            'finalidad' => $finalidad->codigo,
            'plazo_meses' => $finalidad->plazo_retencion_meses,
        ], $titular instanceof Model ? $titular : null);
    }
}
