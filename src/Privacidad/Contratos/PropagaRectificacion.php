<?php

namespace Muni\Shared\Privacidad\Contratos;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo implementa cada sistema que sea modelo de lectura del maestro de personas.
 *
 * El contrato existe para sostener una sola promesa: cuando `aplicar()` termina
 * bien, el maestro YA aceptó el cambio. De ahí sus tres requisitos:
 *
 * 1. **Síncrono.** La implementación tiene que haber hablado con el maestro y
 *    conocido su respuesta antes de devolver. Una implementación que despacha un
 *    job en cola y devuelve `true` NO es válida: informa éxito antes de que el
 *    maestro haya visto nada, y el municipio termina certificando por escrito una
 *    corrección que la próxima sincronización puede pisar. Todo verde en los
 *    tests, y el dato del titular igual de malo que antes.
 * 2. **Devuelve `false`** cuando el maestro rechaza el cambio.
 * 3. **Puede lanzar**, y lanzar se trata como "no propagado", igual que `false`:
 *    la rectificación local se revierte y la solicitud queda en trámite. Esto no
 *    es un detalle teórico: `Muni\Shared\Persona\WriteThrough\SincronizarAlMaestro`
 *    —el transporte natural para envolver acá— llama a `$resp->throw()` ante
 *    cualquier respuesta no exitosa, así que el rechazo del maestro llega como
 *    excepción y nunca como `false`. Ese job, además, es `ShouldQueue`: para
 *    reusarlo hay que ejecutar su `handle()` de forma directa, no despacharlo.
 */
interface PropagaRectificacion
{
    /** @param array<string, mixed> $cambios */
    public function propagar(Model $titular, array $cambios): bool;
}
