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

    /** Todas las finalidades obligan a conservar: no hay nada que suprimir todavía. */
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
