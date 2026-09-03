<?php

namespace Muni\Shared\Persona;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\RequestException;

/**
 * El maestro de personas no respondió, o respondió con error.
 *
 * Existe porque las excepciones del cliente HTTP llevan el RUT adentro: Guzzle
 * arma su mensaje con la URI completa («cURL error 28: … for
 * https://maestro/api/servicios/v1/personas/11111111-1») y Laravel lo conserva
 * al envolverlo en `ConnectionException`; `RequestException` pega además un
 * resumen del cuerpo de la respuesta, donde el maestro puede repetir el RUT.
 * Cualquiera de las dos, subiendo hasta el handler del sistema consumidor,
 * termina en laravel.log y en GlitchTip con un dato personal adentro, y la Ley
 * 21.719 pide minimización también en los registros técnicos.
 *
 * Por eso esta excepción:
 *
 *  - Nunca lleva la URI ni el cuerpo: solo el status HTTP, la clase del fallo
 *    original y —si lo hay— el número de error de cURL, que es lo que sirve
 *    para operar (28 = timeout, 6 = DNS, 7 = conexión rechazada).
 *  - **No encadena la original como `previous`**, a propósito. Monolog imprime
 *    la cadena entera en laravel.log y el SDK de Sentry/GlitchTip la
 *    serializa completa: un RUT escondido en un `previous` es un RUT en el
 *    log, y el paquete no puede controlar cómo reporta cada consumidor.
 *
 * Extiende `RuntimeException` para que los respaldos locales que ya atrapan
 * `Throwable` (discapacidad, feria) sigan funcionando sin tocarse.
 */
final class MaestroNoDisponible extends \RuntimeException
{
    public function __construct(
        string $message,
        /** Status HTTP de la respuesta, o null si no hubo respuesta. */
        public readonly ?int $status = null,
        /** Clase de la excepción original, sin su mensaje. */
        public readonly ?string $causa = null,
        /** Número de error de cURL cuando el fallo fue de red. */
        public readonly ?int $curlErrno = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Construye la excepción a partir del fallo del cliente HTTP, descartando
     * todo lo que pueda llevar el RUT.
     */
    public static function porExcepcion(\Throwable $e): self
    {
        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return new self("El maestro de personas respondió {$status}.", $status, $e::class);
        }

        $errno = self::errnoDeCurl($e);
        $detalle = $errno !== null ? "cURL {$errno}" : class_basename($e);

        return new self("El maestro de personas no respondió ({$detalle}).", null, $e::class, $errno);
    }

    /**
     * El número de error de cURL, si el fallo original lo trae. Laravel envuelve
     * el `ConnectException` de Guzzle como `previous` de su
     * `ConnectionException`; acá se lee el número y se descarta el resto.
     */
    private static function errnoDeCurl(\Throwable $e): ?int
    {
        for ($actual = $e; $actual !== null; $actual = $actual->getPrevious()) {
            if ($actual instanceof ConnectException) {
                $errno = $actual->getHandlerContext()['errno'] ?? null;

                return is_int($errno) ? $errno : null;
            }
        }

        return null;
    }
}
