<?php

namespace Muni\Shared\Privacidad;

/**
 * Lo que una corrida de retención mira y lo que hace, separado a propósito.
 *
 * El resumen anterior era una lista por finalidad y nada más, y como pantalla
 * de confirmación mentía dos veces: sumaba 50.041 «titulares» sobre 20.027
 * personas (los conjuntos por finalidad se superponen y nadie lo decía), y
 * ninguno de esos números era el que iba a suprimirse.
 */
final readonly class ResumenDeRetencion
{
    /**
     * @param  list<array{finalidad: string, titulares: int}>  $porFinalidad  cuántos venció cada finalidad, por separado; los conjuntos se superponen
     * @param  array<string, int>  $finalidades  código => plazo en meses de las finalidades consideradas
     * @param  int  $personas  personas distintas vencidas en AL MENOS una finalidad
     * @param  int  $suprimibles  personas vencidas en TODAS: las únicas que corresponde suprimir
     * @param  int  $suprimidos  personas efectivamente suprimidas (0 en simulación; menor que $suprimibles si la corrida se cortó)
     */
    public function __construct(
        public array $porFinalidad,
        public array $finalidades,
        public int $personas,
        public int $suprimibles,
        public int $suprimidos,
    ) {}

    /**
     * Se mide por personas y no por `porFinalidad`, que ahora trae también las
     * finalidades en cero: un sistema con política de retención sembrada y sin
     * nadie vencido tiene que seguir diciendo «no hay titulares con plazo
     * vencido», no imprimir una tabla de ceros.
     */
    public function sinNadaQueRevisar(): bool
    {
        return $this->personas === 0;
    }
}
