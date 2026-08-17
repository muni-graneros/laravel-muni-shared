<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

/**
 * Declaración explícita: «este sistema no es modelo de lectura del maestro de
 * personas, la supresión local es la definitiva».
 *
 * No es un enlace por defecto y no lo pone el service provider: lo enlaza el
 * sistema adoptante, a mano, y eso es todo el punto. Un default que devolviera
 * éxito en silencio es exactamente el defecto que este módulo acaba de
 * arreglar —el ecosistema conservando la identidad de alguien a quien se le
 * respondió por escrito que sus datos fueron suprimidos—, y la lección de
 * `disco_evidencia` es que un default cómodo no reduce el riesgo de mala
 * configuración: lo esconde mejor.
 *
 * Responde `noCorrespondia()` y no `aceptada()`, y la diferencia no es
 * cosmética: acá **nadie habló con ningún maestro**, así que decir que el
 * maestro aceptó sería la misma frase falsa que el tercer estado vino a
 * eliminar. Con esto, la evidencia de una supresión distingue por sí sola al
 * sistema que propagó del que no tenía a quién propagar, sin que haya que ir a
 * mirar qué tenía enlazado ese sistema aquel día.
 */
class SupresionSoloLocal implements PropagaSupresion
{
    public function propagar(TitularDeDatos $titular, string $documento): ResultadoDePropagacion
    {
        return ResultadoDePropagacion::noCorrespondia(
            'Este sistema declaró que no es modelo de lectura del maestro de personas '
            .'(SupresionSoloLocal): la supresión local es la definitiva.',
        );
    }
}
