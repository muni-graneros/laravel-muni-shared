<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;

class Textos
{
    public function publicar(string $codigo, string $contenido): TextoInformativo
    {
        return DB::transaction(function () use ($codigo, $contenido): TextoInformativo {
            $sistema = (string) config('privacidad.sistema');

            $anterior = $this->vigente($codigo);

            if ($anterior !== null) {
                // Por query builder: el modelo es inmutable y rechazaría updating.
                TextoInformativo::query()->whereKey($anterior->getKey())
                    ->update(['vigente_hasta' => now()]);
            }

            $ultima = TextoInformativo::query()->delSistema($sistema)
                ->where('codigo', $codigo)->max('version') ?? 0;

            return TextoInformativo::create([
                'sistema' => $sistema,
                'codigo' => $codigo,
                'version' => $ultima + 1,
                'contenido' => $contenido,
                'hash' => hash('sha256', $contenido),
                'vigente_desde' => now(),
            ]);
        });
    }

    public function vigente(string $codigo): ?TextoInformativo
    {
        return TextoInformativo::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('codigo', $codigo)
            ->vigentes()
            ->orderByDesc('version')
            ->first();
    }
}
