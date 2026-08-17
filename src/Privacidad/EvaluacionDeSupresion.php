<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * Hasta dónde alcanza el derecho de supresión de UN titular, según el RAT.
 *
 * Se calcula sin efectos —`Supresiones::evaluar()` no escribe nada— para que un
 * panel pueda mostrarle al funcionario qué va a pasar ANTES de que resuelva, y
 * para que el fundamento que le escriba al titular pueda citar la norma que
 * impide en vez de inventarla.
 *
 * Qué afirma esta evaluación, con precisión: que el Registro de Actividades de
 * Tratamiento declara (o no declara) una finalidad activa, fundada en una norma
 * habilitante, con plazo de retención declarado y todavía corriendo para este
 * titular. Lo que NO afirma —y ningún código lo puede afirmar— es que esa norma
 * efectivamente obligue a conservar el dato: eso es una lectura jurídica que
 * hace quien resuelve, con la cita a la vista. El módulo señala el impedimento
 * declarado; no lo interpreta.
 */
final readonly class EvaluacionDeSupresion
{
    /**
     * @param  list<Finalidad>  $impiden  las que obligan a conservar
     * @param  list<Finalidad>  $cesan  aquellas sobre las que el derecho procede
     */
    public function __construct(
        public array $impiden,
        public array $cesan,
    ) {}

    /**
     * Nada impide suprimir: se destruye todo.
     *
     * Un sistema que no declaró NINGUNA finalidad activa cae acá, y es
     * deliberado: no tiene con qué negarse. Un municipio no puede oponerle al
     * titular una obligación de conservación que nunca declaró a la autoridad
     * —el RAT es justamente el documento donde se declara—, y el barrido por
     * retención ya trata igual a las finalidades que no lo alcanzan.
     */
    public function procedeTotal(): bool
    {
        return $this->impiden === [];
    }

    /** Algo se conserva por norma, y algo cesa. */
    public function esParcial(): bool
    {
        return $this->impiden !== [] && $this->cesan !== [];
    }

    /**
     * Todas las finalidades obligan a conservar: no hay nada que suprimir
     * todavía.
     *
     * En la práctica es un estado difícil de alcanzar, y conviene saberlo
     * antes de apoyarse en él: basta UNA finalidad por consentimiento, o una
     * por función legal sin `plazo_retencion_meses` declarado, o una cuyo plazo
     * ya venció para este titular, para que algo cese y la evaluación caiga en
     * `esParcial()`. Montar este caso en los tests del módulo exigió desactivar
     * finalidades del RAT sembrado.
     *
     * Dicho al revés, que es como importa: **un RAT realista casi nunca niega
     * una supresión entera**. La respuesta habitual del sistema a una solicitud
     * de supresión es la acogida parcial —cesa lo que puede cesar, se conserva
     * lo que la norma manda—, y eso es lo que hay que saber explicar en el
     * mesón.
     */
    public function noProcede(): bool
    {
        return $this->impiden !== [] && $this->cesan === [];
    }

    /** @return list<string> */
    public function codigosQueImpiden(): array
    {
        return array_map(fn (Finalidad $f): string => (string) $f->codigo, $this->impiden);
    }

    /** @return list<string> */
    public function codigosQueCesan(): array
    {
        return array_map(fn (Finalidad $f): string => (string) $f->codigo, $this->cesan);
    }

    /**
     * Código de finalidad => norma que la habilita.
     *
     * La norma nunca viene vacía: `Finalidad::validarInvariantes()` rechaza al
     * guardar una finalidad fundada en la ley que no diga en cuál, y solo esas
     * llegan a impedir.
     *
     * @return array<string, string>
     */
    public function normasQueImpiden(): array
    {
        $normas = [];

        foreach ($this->impiden as $finalidad) {
            $normas[(string) $finalidad->codigo] = (string) $finalidad->norma_habilitante;
        }

        return $normas;
    }

    /**
     * El texto que se le puede mostrar al funcionario y citar al titular.
     *
     * Lleva la norma y el plazo, que es lo que convierte una negativa en una
     * respuesta fundada: «no se puede todavía» sin la cita es exactamente la
     * respuesta que una fiscalización no acepta.
     */
    public function explicacion(): string
    {
        if ($this->procedeTotal()) {
            return 'Ninguna finalidad declarada obliga a conservar estos datos: la supresión procede completa.';
        }

        $lineas = array_map(
            fn (Finalidad $f): string => "«{$f->nombre}» ({$f->codigo}): se trata por {$f->base_licitud->etiqueta()} "
                ."según {$f->norma_habilitante}, con un plazo de conservación de {$f->plazo_retencion_meses} meses "
                .'que todavía corre para este titular.',
            $this->impiden,
        );

        $texto = 'No se puede suprimir todavía. '.implode(' ', $lineas);

        if ($this->cesan !== []) {
            $texto .= ' Sí cesa el tratamiento para: '.implode(', ', $this->codigosQueCesan()).'.';
        }

        return $texto;
    }
}
