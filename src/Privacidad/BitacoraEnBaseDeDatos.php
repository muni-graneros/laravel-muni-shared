<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;

class BitacoraEnBaseDeDatos implements RegistroDeEvidencia
{
    /** @param array<string, mixed> $datos */
    public function registrar(string $evento, array $datos, ?Model $titular = null): void
    {
        EntradaBitacora::create([
            'sistema' => (string) config('privacidad.sistema'),
            'evento' => $evento,
            // getMorphClass() y no ::class: si el sistema declaró un morph map,
            // se guarda el alias estable en vez del FQCN. Estas filas son
            // evidencia legal y tienen que sobrevivir a que alguien mueva o
            // renombre la clase del modelo años después.
            'titular_type' => $titular?->getMorphClass(),
            'titular_id' => $titular?->getKey(),
            'datos' => $datos,
            'user_id' => Auth::id(),
            'ocurrido_en' => now(),
        ]);
    }
}
