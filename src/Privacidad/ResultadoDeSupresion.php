<?php

namespace Muni\Shared\Privacidad;

/**
 * Qué hizo realmente una supresión a petición del titular.
 *
 * Existe para que quien la pide no tenga que deducirlo de la ausencia de
 * excepción, que es la forma en que este módulo ya certificó una vez algo que
 * no había ocurrido. Un panel que atiende el mesón necesita decirle al titular
 * dos cosas distintas: «sus datos ya no están» o «se dejó de tratarlos para
 * estas finalidades y estas otras se conservan por esta norma».
 */
final readonly class ResultadoDeSupresion
{
    /**
     * @param  bool  $total  si los datos se destruyeron; false = acogida parcial
     * @param  ?ResultadoDesvinculacion  $barrido  el corte del vínculo con el
     *                                             módulo, solo en la total
     * @param  int  $finalidadesCesadas  cuántos bloqueos definitivos quedaron
     * @param  ?ResultadoDePropagacion  $propagacion  qué pasó en el maestro de
     *                                                personas. **null en la
     *                                                acogida parcial**, y no por
     *                                                omisión: la parcial no
     *                                                destruye nada, así que no
     *                                                hay ninguna baja que
     *                                                propagar. En la total nunca
     *                                                es null, y hay que mirarla
     *                                                antes de decirle a nadie
     *                                                que sus datos ya no están
     *                                                en el ecosistema:
     *                                                `loAceptoElMaestro()`
     *                                                falso significa que la
     *                                                identidad puede seguir viva
     *                                                en el registro federado.
     */
    public function __construct(
        public bool $total,
        public EvaluacionDeSupresion $evaluacion,
        public ?ResultadoDesvinculacion $barrido = null,
        public int $finalidadesCesadas = 0,
        public ?ResultadoDePropagacion $propagacion = null,
    ) {}
}
