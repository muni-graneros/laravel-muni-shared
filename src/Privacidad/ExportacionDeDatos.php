<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Ciclo\EntregaDeCopia;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * Sirve al derecho de acceso y al de portabilidad: son el mismo dato, cambia
 * el formato en que se entrega.
 */
class ExportacionDeDatos
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /**
     * Entrada documentada del módulo: exporta a partir de la solicitud, no de
     * un titular suelto.
     *
     * La diferencia no es de estilo. Entregar el expediente completo de una
     * persona es la comunicación de datos más amplia que hace este módulo, y
     * `paraTitular()` la hace para cualquier titular que reciba, sin exigir que
     * exista una solicitud con identidad verificada y sin dejar rastro. Cableado
     * al panel de la forma natural —una acción que toma un id del request— eso
     * es un IDOR sin bitácora: nadie podría reconstruir después a quién se le
     * entregó qué. Acá el id viaja dentro de la solicitud, que ya pasó por la
     * verificación de identidad de `Solicitudes::registrar()`, y la entrega
     * queda registrada.
     *
     * @return array<string, mixed>
     */
    public function paraSolicitud(Solicitud $solicitud): array
    {
        // Las tres reglas —qué tipo da derecho, qué estado lo niega y que haya
        // titular vigente— viven en `EntregaDeCopia`, para que los paneles
        // puedan PREGUNTAR antes de ofrecer el botón en vez de descubrirlo con
        // una excepción cuando el funcionario ya lo apretó delante del vecino.
        $motivo = EntregaDeCopia::porQueNo($solicitud);

        if ($motivo !== null) {
            throw new ResolucionInvalida($motivo);
        }

        $titular = $solicitud->titular;

        if (! $titular instanceof TitularDeDatos) {
            throw new ResolucionInvalida(
                "La solicitud #{$solicitud->getKey()} no tiene un titular vigente del que exportar datos.",
            );
        }

        $datos = $this->paraTitular($titular);

        // Ids y NOMBRES de campo, nunca valores: mismo criterio que en
        // Rectificaciones. La bitácora no está cifrada, y copiar ahí el
        // expediente exportado duplicaría exactamente los datos personales que
        // este módulo existe para minimizar.
        $this->evidencia->registrar('datos.exportados', [
            'solicitud_id' => $solicitud->getKey(),
            'tipo' => $solicitud->tipo->value,
            'campos' => array_keys($datos['datos']),
        ], $titular);

        return $datos;
    }

    /**
     * Arma la copia sin registrar nada. Queda pública para quien ya tiene la
     * trazabilidad resuelta por otra vía (una previsualización interna, un
     * informe agregado); para atender el derecho de un titular, usar
     * `paraSolicitud()`.
     *
     * @return array<string, mixed>
     */
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
