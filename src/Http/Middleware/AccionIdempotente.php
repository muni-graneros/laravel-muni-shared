<?php

declare(strict_types=1);

namespace Muni\Shared\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hace idempotente un POST que un cliente con cola sin conexión puede reintentar.
 *
 * Si la respuesta se pierde en una red mala, el cliente repite la misma acción y,
 * sin esto, se ejecuta dos veces (dos incidentes, dos registros). El cliente manda
 * una `Idempotency-Key` (UUID) estable por acción; acá se guarda (por defecto 24 h)
 * el status y el cuerpo de la PRIMERA respuesta 2xx JSON y un repetido recibe esa
 * misma respuesta, marcada `Idempotent-Replayed: true`, sin ejecutar nada.
 *
 * - Sin cabecera: pasa tal cual (clientes viejos que aún no la mandan).
 * - Cabecera que no es UUID: 422, sin ejecutar.
 * - La clave de caché lleva el id del usuario, el método y la ruta concreta: una
 *   clave de otro usuario (o usada en otra ruta) no devuelve la respuesta ajena
 *   (IDOR). Sin usuario autenticado no hay con qué aislar: se ejecuta como siempre.
 * - Lock atómico por clave: dos reintentos simultáneos no ejecutan a la vez. Si no
 *   se consigue se responde 503 + Retry-After, NO 409: una cola típica descarta los
 *   4xx (salvo 429) y reintenta los 5xx.
 * - Sólo se guardan respuestas 2xx JSON; un error se vuelve a ejecutar.
 *
 * Configuración (publicable, `idempotencia-config`): `idempotencia.ttl`,
 * `.prefijo`, `.espera_del_lock`, `.vida_del_lock`.
 */
class AccionIdempotente
{
    public const CABECERA = 'Idempotency-Key';

    public static function claveDeCache(int $usuarioId, string $ruta, string $clave): string
    {
        return self::prefijo().':respuesta:'.self::huella($usuarioId, $ruta, $clave);
    }

    public static function claveDeLock(int $usuarioId, string $ruta, string $clave): string
    {
        return self::prefijo().':lock:'.self::huella($usuarioId, $ruta, $clave);
    }

    private static function prefijo(): string
    {
        return (string) config('idempotencia.prefijo', 'idempotencia');
    }

    private static function huella(int $usuarioId, string $ruta, string $clave): string
    {
        return $usuarioId.':'.hash('sha256', $ruta."\n".strtolower($clave));
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

        if ($usuario === null) {
            return $next($request);
        }

        $usuarioId = (int) $usuario->getAuthIdentifier();
        $ruta = $request->method().' '.$request->path();
        $cache = self::claveDeCache($usuarioId, $ruta, $clave);
        $lock = Cache::lock(
            self::claveDeLock($usuarioId, $ruta, $clave),
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
                return JsonResponse::fromJsonString(
                    $guardada['cuerpo'],
                    $guardada['status'],
                    ['Idempotent-Replayed' => 'true'],
                );
            }

            $respuesta = $next($request);

            if ($respuesta->isSuccessful() && $respuesta instanceof JsonResponse) {
                Cache::put($cache, [
                    'status' => $respuesta->getStatusCode(),
                    'cuerpo' => (string) $respuesta->getContent(),
                ], (int) config('idempotencia.ttl', 86_400));
            }

            return $respuesta;
        } finally {
            $lock->release();
        }
    }
}
