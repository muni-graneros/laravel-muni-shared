<?php

namespace Muni\Shared\Privacidad\Contratos;

use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * Cada sistema sabe desde cuándo trata a un titular bajo una finalidad: puede
 * ser la fecha de registro, la última atención o el cierre del caso. El módulo
 * no puede adivinarlo, así que lo pregunta.
 *
 * Qué significa exactamente «vencido», porque de esto depende que se destruya o
 * no un registro: **a quien esta finalidad ya no necesita**. Eso incluye dos
 * casos, y el segundo es fácil de olvidar:
 *
 * 1. El titular cumplió el plazo de la finalidad.
 * 2. Esta finalidad nunca lo alcanzó (nunca tuvo una cita, si la finalidad es
 *    `agenda_citas`). Una finalidad que no trata a alguien no tiene por qué
 *    conservarlo.
 *
 * Importa porque `AplicarRetencion` suprime a quien aparece como vencido en
 * TODAS las finalidades con plazo: una persona está terminada cuando ninguna
 * finalidad la necesita, no cuando venció la primera. Un resolvedor que en el
 * caso 2 no devuelva al titular lo deja fuera de la intersección y esa persona
 * **no se anonimiza nunca**. Falla del lado seguro —conserva de más, no destruye
 * de más— pero conservar indefinidamente también es una infracción, así que hay
 * que mirarlo al escribir el resolvedor.
 *
 * Dos cosas más que el resolvedor debe cumplir:
 *
 * - **Excluir a los ya anonimizados** (en el ecosistema, `nro_documento not like
 *   'ANON-%'`). Sin eso, cada corrida los vuelve a procesar.
 * - **No repetir titulares** dentro de una misma finalidad. El módulo
 *   des-duplica por si acaso, porque un `join` sin `distinct` haría que una sola
 *   finalidad contara como varias y anonimizaría a alguien que otra todavía
 *   necesita, pero el resolvedor no debería depender de eso.
 *
 * Se llama DOS veces por corrida: una para contar y otra para suprimir. Debe ser
 * consultable, no destructivo, y conviene que la columna de corte tenga índice
 * (en la corrida real, sin índice, eran 5,9 s por pasada sobre 20 mil personas,
 * escalando lineal).
 */
interface ResuelveTitularesVencidos
{
    /** @return iterable<int, TitularDeDatos> */
    public function vencidos(Finalidad $finalidad): iterable;
}
