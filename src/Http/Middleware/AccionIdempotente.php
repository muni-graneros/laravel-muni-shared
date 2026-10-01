<?php

declare(strict_types=1);

namespace Muni\Shared\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hace idempotente un POST que un cliente con cola sin conexión puede reintentar.
 *
 * Si la respuesta se pierde en una red mala, el cliente repite la misma acción y,
 * sin esto, se ejecuta dos veces (dos incidentes, dos registros). El cliente manda
 * una `Idempotency-Key` (UUID) estable por acción; acá se guarda (por defecto 24 h)
 * el status y el cuerpo de la PRIMERA respuesta 2xx y un repetido recibe esa misma
 * respuesta, marcada `Idempotent-Replayed: true`, sin ejecutar nada.
 *
 * - Sin cabecera: pasa tal cual (clientes viejos que aún no la mandan).
 * - Cabecera que no es UUID: 422, sin ejecutar.
 * - La clave de caché lleva la clase y el identificador (como texto) del usuario,
 *   el método y la ruta concreta: una clave de otro usuario (o usada en otra ruta o
 *   con otro método) no devuelve la respuesta ajena (IDOR). Sin usuario autenticado
 *   no hay con qué aislar: se ejecuta como siempre.
 * - Lock atómico por clave: dos reintentos simultáneos no ejecutan a la vez. Si no
 *   se consigue se responde 503 + Retry-After, NO 409: una cola típica descarta los
 *   4xx (salvo 429) y reintenta los 5xx.
 * - Se guarda toda respuesta 2xx con cuerpo en memoria (JSON, texto, 204) con su
 *   Content-Type; un error se vuelve a ejecutar. Un stream o un archivo
 *   (`StreamedResponse`, `BinaryFileResponse`) no se puede guardar y se ejecuta cada
 *   vez; un redirect (3xx) tampoco se guarda: este middleware es para APIs.
 * - La repetición devuelve sólo status, cuerpo y Content-Type: ninguna otra cabecera
 *   de la respuesta original (cookies incluidas).
 * - La clave no ata el CUERPO de la petición: el mismo UUID con otro cuerpo recibe
 *   la primera respuesta. El cliente debe generar un UUID nuevo por acción.
 *
 * Configuración (publicable, `idempotencia-config`): `idempotencia.ttl`,
 * `.prefijo`, `.espera_del_lock`, `.vida_del_lock`.
 */
class AccionIdempotente
{
    public const CABECERA = 'Idempotency-Key';

    public static function claveDeCache(Authenticatable $usuario, string $ruta, string $clave): string
    {
        return self::prefijo().':respuesta:'.self::huella($usuario, $ruta, $clave);
    }

    public static function claveDeLock(Authenticatable $usuario, string $ruta, string $clave): string
    {
        return self::prefijo().':lock:'.self::huella($usuario, $ruta, $clave);
    }

    private static function prefijo(): string
    {
        return (string) config('idempotencia.prefijo', 'idempotencia');
    }

    /**
     * El identificador va como TEXTO, nunca `(int)`: con ids UUID un cast dejaba a
     * todos los usuarios cuyo id empieza con letra en el mismo 0, y uno recibía la
     * respuesta del otro. La clase del modelo separa dos guards cuyos ids chocan.
     */
    private static function huella(Authenticatable $usuario, string $ruta, string $clave): string
    {
        $identificador = $usuario::class."\n".self::comoTexto($usuario->getAuthIdentifier());

        return hash('sha256', $identificador."\n".$ruta."\n".strtolower($clave));
    }

    private static function comoTexto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $clave = $request->header(self::CABECERA);

        if ($clave === null) {
            return $next($request);
        }

        if (! is_string($clave) || ! Str::isUuid($clave)) {
            return new JsonResponse(
                ['message' => 'La clave de idempotencia no es válida.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $usuario = $request->user();

        if (! $usuario instanceof Authenticatable || self::comoTexto($usuario->getAuthIdentifier()) === '') {
            return $next($request);
        }

        $ruta = $request->method().' '.$request->path();
        $cache = self::claveDeCache($usuario, $ruta, $clave);
        $lock = Cache::lock(
            self::claveDeLock($usuario, $ruta, $clave),
            (int) config('idempotencia.vida_del_lock', 60),
        );

        try {
            $lock->block((int) config('idempotencia.espera_del_lock', 3));
        } catch (LockTimeoutException) {
            return new JsonResponse(
                ['message' => 'La acción sigue en proceso: reintenta en unos segundos.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '2'],
            );
        }

        try {
            $guardada = Cache::get($cache);

            if (is_array($guardada)) {
                return new Response(
                    (string) $guardada['cuerpo'],
                    (int) $guardada['status'],
                    [
                        'Content-Type' => $guardada['tipo'] ?? 'application/json',
                        'Idempotent-Replayed' => 'true',
                    ],
                );
            }

            $respuesta = $next($request);

            if ($respuesta->isSuccessful() && self::sePuedeGuardar($respuesta)) {
                Cache::put($cache, [
                    'status' => $respuesta->getStatusCode(),
                    'cuerpo' => (string) $respuesta->getContent(),
                    'tipo' => $respuesta->headers->get('Content-Type'),
                ], (int) config('idempotencia.ttl', 86_400));
            }

            return $respuesta;
        } finally {
            $lock->release();
        }
    }

    /** Un stream o un archivo no tienen cuerpo en memoria que repetir: se ejecutan cada vez. */
    private static function sePuedeGuardar(Response $respuesta): bool
    {
        return ! $respuesta instanceof StreamedResponse
            && ! $respuesta instanceof BinaryFileResponse
            && $respuesta->getContent() !== false;
    }
}
