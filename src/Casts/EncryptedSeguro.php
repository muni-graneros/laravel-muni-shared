<?php

namespace Muni\Shared\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Cifrado en reposo resiliente.
 *
 * A diferencia del cast nativo `encrypted` (que lanza DecryptException ante
 * cualquier valor que no sea texto cifrado válido), este cast degrada con
 * elegancia: si el valor todavía está en texto plano (fila heredada que aún no
 * pasó por la migración, o escrita por una ruta que evita el modelo), lo devuelve
 * tal cual en vez de hacer crashear la vista.
 *
 * - Escritura: cifra siempre (los datos quedan cifrados en reposo).
 * - Lectura: descifra; si no es descifrable, devuelve el valor crudo.
 *
 * Portado desde `App\Casts\EncryptedSeguro`, que vivía copiado en 7 de los 8
 * sistemas del ecosistema (todos menos atencionvecino) byte a byte salvo una
 * palabra de comentario. Se conserva el comportamiento exacto: cambiarlo acá
 * dejaría ilegible lo que ya está escrito en siete bases de producción.
 *
 * Diferencia con `Privacidad\CifradoCast`, que también existe en este paquete y
 * NO lo reemplaza: aquel reconoce el texto cifrado por la FORMA del payload, y
 * por eso un ciphertext manipulado o escrito con otra APP_KEY truena en vez de
 * leerse; este otro devuelve el valor crudo ante cualquier fallo de descifrado.
 * Para la evidencia ARCOP se quiere lo primero (que truene); para una columna
 * operativa a medio migrar, lo segundo. Elegir a conciencia.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class EncryptedSeguro implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value; // texto plano heredado: no romper la lectura
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString($value);
    }
}
