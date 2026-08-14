<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;

/**
 * Corta el vínculo entre la bitácora y un titular que se anonimizó.
 *
 * Anonimizar la ficha y dejar intacta la bitácora que apunta a ella es
 * anonimización a medias: el hecho auditable tiene que sobrevivir, el vínculo no.
 */
class Bitacora
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /** @return int cuántas entradas quedaron desvinculadas */
    public function desvincular(Model $titular): int
    {
        return DB::transaction(function () use ($titular): int {
            $ref = (string) Str::ulid();

            // Por query builder a propósito: el modelo es append-only y rechaza
            // `updating`. Cortar el vínculo es la única mutación admitida, y queda
            // registrada abajo con su propia entrada.
            //
            // titular_type NO se limpia, a propósito: un nombre de clase morph
            // ("PersonaDePrueba", "Persona") no identifica a nadie, solo dice de
            // qué tipo de sujeto trataba la entrada. Se deja para no perder ese
            // contexto sin ganar nada en anonimización.
            $afectadas = EntradaBitacora::query()
                ->where('titular_type', $titular->getMorphClass())
                ->where('titular_id', $titular->getKey())
                ->update(['titular_id' => null, 'titular_ref' => $ref]);

            if ($afectadas > 0) {
                // El ref viaja en texto plano, pero solo es legible contra filas
                // que YA quedaron huérfanas (titular_id null): no hay ningún
                // titular_id vivo cerca de él dentro de esta transacción. Si algún
                // cambio futuro vuelve a poner un titular_id vivo junto a esta
                // entrada, revisar dos veces — eso reabre el join de dos saltos
                // que este método existe para cerrar.
                $this->evidencia->registrar('bitacora.desvinculada', [
                    'entradas' => $afectadas,
                    'titular_ref' => $ref,
                ]);
            }

            return $afectadas;
        });
    }
}
