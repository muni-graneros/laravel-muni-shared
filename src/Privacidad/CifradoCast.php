<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast del texto libre del módulo: cifrado en la base, en claro en el modelo.
 *
 * Es el `encrypted` de Laravel con una diferencia que importa para ocho
 * sistemas en producción: **tolera las filas escritas antes del cifrado**. El
 * cast nativo lanza `DecryptException` al leer texto plano, o sea que entre
 * el despliegue de esta versión y la corrida de `privacidad:cifrar-texto-libre`
 * ningún panel podría abrir una solicitud vieja. Acá una fila en claro se lee
 * en claro, se cifra al reescribirla, y el comando cifra el resto de una vez.
 *
 * La tolerancia es angosta a propósito: se reconoce un valor cifrado por la
 * forma del payload (base64 de un JSON con `iv`, `value` y `mac`, que es lo que
 * escribe el Encrypter). Lo que NO tiene esa forma es texto plano y se devuelve
 * tal cual; lo que SÍ la tiene y no valida su MAC —una APP_KEY cambiada, una
 * fila manipulada— truena, como debe. No hay camino por el que un ciphertext
 * inválido se lea como si fuera texto.
 *
 * Se usa con `CifradoCast::class` para texto y `CifradoCast::class.':array'`
 * para un documento JSON (`verificacion_identidad`).
 *
 * Y la consecuencia operativa, que va en el README: la APP_KEY pasa a ser parte
 * de la evidencia. Rotarla sin recifrar deja ilegible el expediente ARCOP.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class CifradoCast implements CastsAttributes
{
    private readonly bool $array;

    public function __construct(?string $formato = null)
    {
        $this->array = $formato === 'array';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $claro = self::descifrar((string) $value);

        if (! $this->array) {
            return $claro;
        }

        // Como el cast `array` de Laravel: lo que json_decode devuelva. Una
        // columna con algo que no es JSON —una migración de datos ajena— da
        // null, y el que llama decide (Bitacora::purgarRutasJson la reescribe).
        return json_decode((string) $claro, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($this->array) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }

        return self::cifrar((string) $value);
    }

    /**
     * Cifra un valor tal como lo haría el cast, para los `update()` masivos y
     * los `DB::table()` que no pasan por el modelo.
     */
    public static function cifrar(string $claro): string
    {
        return Model::currentEncrypter()->encrypt($claro, false);
    }

    /**
     * Lee un valor de la columna sea cifrado o —fila anterior a esta versión—
     * en claro. Con un payload cifrado que no valida, lanza `DecryptException`.
     *
     * @throws DecryptException
     */
    public static function descifrar(?string $crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        if (! self::estaCifrado($crudo)) {
            return $crudo;
        }

        return Model::currentEncrypter()->decrypt($crudo, false);
    }

    /**
     * Si el valor tiene la forma del payload del Encrypter de Laravel.
     *
     * Un texto plano que casualmente sea base64 válido no engaña la
     * comprobación: además tiene que decodificar a un JSON con las tres claves
     * del payload.
     */
    public static function estaCifrado(?string $crudo): bool
    {
        if ($crudo === null || $crudo === '') {
            return false;
        }

        $decodificado = base64_decode($crudo, true);

        if ($decodificado === false) {
            return false;
        }

        $payload = json_decode($decodificado, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac'])
            && is_string($payload['iv'])
            && is_string($payload['value'])
            && is_string($payload['mac']);
    }

    /**
     * Las columnas que un modelo cifra con este cast, en el orden en que las
     * declara. Es lo que leen el comando de migración de datos y las guardias
     * de deriva: el modelo es la única fuente de qué va cifrado.
     *
     * @return list<string>
     */
    public static function columnasCifradasDe(Model $modelo): array
    {
        $columnas = [];

        foreach ($modelo->getCasts() as $columna => $cast) {
            if ($cast === self::class || str_starts_with((string) $cast, self::class.':')) {
                $columnas[] = $columna;
            }
        }

        return $columnas;
    }
}
