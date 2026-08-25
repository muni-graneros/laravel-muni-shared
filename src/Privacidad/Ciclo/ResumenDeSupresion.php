<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\ResultadoDeSupresion;

/**
 * Qué pasó realmente en una supresión, que es lo que hay que poder decirle al
 * titular.
 *
 * Los desenlaces se cuentan distinto a propósito: una acogida parcial NO borró
 * nada, y anunciarla con el mismo «listo» que la supresión total sería la
 * confusión que esta clase existe para cerrar. Y dentro de la total hay una
 * segunda distinción: destruir el dato local no es lo mismo que sacar al vecino
 * del ecosistema. Hasta que el maestro de personas conteste que aceptó, no hay
 * con qué afirmar que la identidad dejó de servirse por RUT a los otros
 * sistemas.
 */
final readonly class ResumenDeSupresion
{
    public function __construct(
        public bool $total,
        public int $archivosSuprimidos,
        public int $archivosNoEncontrados,
        public bool $salioDelEcosistema,
    ) {}

    public static function de(ResultadoDeSupresion $resultado): self
    {
        return new self(
            total: $resultado->total,
            archivosSuprimidos: $resultado->barrido->archivosSuprimidos ?? 0,
            archivosNoEncontrados: $resultado->barrido->archivosNoEncontrados ?? 0,
            salioDelEcosistema: $resultado->propagacion?->loAceptoElMaestro() ?? false,
        );
    }

    public function esAdvertencia(): bool
    {
        return $this->total && ! $this->salioDelEcosistema;
    }

    public function titulo(): string
    {
        if (! $this->total) {
            return 'Supresión acogida en parte';
        }

        return $this->salioDelEcosistema
            ? 'Datos suprimidos'
            : 'Suprimido acá, pero la identidad sigue en el ecosistema';
    }

    public function cuerpo(): string
    {
        if (! $this->total) {
            return 'La supresión procede solo en parte: el módulo cesó las finalidades que podían cesar y dejó las '
                .'que una norma obliga a conservar. No se borró el registro.';
        }

        $cuerpo = 'El registro quedó anonimizado, se purgaron sus datos sensibles y el módulo borró '
            .$this->archivosSuprimidos.' documento(s) del disco. La solicitud queda acogida.';

        if ($this->archivosNoEncontrados > 0) {
            // No es cosmético: una ruta sin archivo en el disco declarado puede
            // significar que el documento sigue vivo en OTRO disco, y el módulo
            // ya no tiene con qué encontrarlo.
            $cuerpo .= ' Ojo: '.$this->archivosNoEncontrados.' ruta(s) no tenían archivo donde el módulo buscó. '
                .'Avisar a informática para descartar que el documento haya quedado en otro disco.';
        }

        if (! $this->salioDelEcosistema) {
            $cuerpo .= ' El maestro de personas todavía no confirmó la baja: hasta que lo haga, la identidad se '
                .'puede seguir sirviendo por RUT a los otros sistemas.';
        }

        return $cuerpo;
    }
}
