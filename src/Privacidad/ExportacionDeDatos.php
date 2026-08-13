<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

/**
 * Sirve al derecho de acceso y al de portabilidad: son el mismo dato, cambia
 * el formato en que se entrega.
 */
class ExportacionDeDatos
{
    /** @return array<string, mixed> */
    public function paraTitular(TitularDeDatos $titular): array
    {
        return [
            'generado_en' => now()->toIso8601String(),
            'responsable' => [
                'nombre' => (string) config('privacidad.responsable.nombre'),
                'contacto' => (string) config('privacidad.responsable.contacto'),
                'delegado' => (string) config('privacidad.responsable.delegado'),
            ],
            'titular' => [
                'nombre' => $titular->titularNombre(),
                'documento' => $titular->titularDocumento(),
            ],
            'datos' => $titular->exportarDatosPersonales(),
        ];
    }

    public function comoJson(TitularDeDatos $titular): string
    {
        // Sin escapar unicode: el titular tiene que poder leer su propio nombre.
        return json_encode(
            $this->paraTitular($titular),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
