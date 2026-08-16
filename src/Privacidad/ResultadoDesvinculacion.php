<?php

namespace Muni\Shared\Privacidad;

/**
 * Cuánto barrió una desvinculación, para quien la haya pedido.
 *
 * Existe porque estas cantidades ya NO pueden viajar en la constancia por
 * titular. Son cantidades por persona publicadas en el instante exacto de la
 * anonimización, y con eso se acota cada persona a un conjunto de 2 a 6 filas
 * huérfanas; combinadas con el orden de las constancias, se resuelve el resto.
 * Es el único de los tres canales de correlación conocidos que el módulo
 * controla (los otros dos —fechas de negocio e ids de fila— no se pueden cerrar
 * sin destruir el hecho auditable; ver el pendiente 5-ter del spec).
 *
 * Así que se devuelven a quien llamó, y es quien llamó el que decide qué
 * publicar: `AplicarRetencion` las suma y deja UNA constancia por corrida.
 */
final readonly class ResultadoDesvinculacion
{
    public function __construct(
        public int $filas,
        public int $archivosSuprimidos,
        public int $archivosNoEncontrados,
    ) {}
}
