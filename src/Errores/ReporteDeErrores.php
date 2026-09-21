<?php

declare(strict_types=1);

namespace Muni\Shared\Errores;

use Illuminate\Support\Facades\Log;

/**
 * Decide si las trazas de excepción pueden salir hacia el destino configurado.
 *
 * `sentry/sentry-laravel` habla el protocolo de Sentry, y GlitchTip —que es lo
 * que el ecosistema autoaloja— lo entiende. El mismo paquete sirve para las dos
 * cosas: lo único que cambia es el host del DSN. Por eso el host es lo que hay
 * que mirar.
 *
 * Una traza lleva la ruta, la consulta y a veces el cuerpo del request; en un
 * sistema municipal eso incluye datos de un vecino. Mandarla a sentry.io es una
 * transferencia internacional de datos personales, que la Ley 21.719 no permite
 * sin base de licitud. La decisión de autoalojar ya estaba tomada, pero vivía en
 * un documento de diseño: acá está en el código, donde no se puede desobedecer
 * pegando un DSN en un `.env`.
 *
 * Ante cualquier duda —DSN vacío, ilegible, sin host— NO se manda nada. Perder
 * el reporte de un error cuesta mucho menos que mandarlo a donde no corresponde.
 *
 * Portada byte a byte desde `App\Support\ReporteDeErrores`, idéntica en 7 de los
 * 8 sistemas del ecosistema. Se usa desde `bootstrap/app.php`:
 *
 *     use Muni\Shared\Errores\ReporteDeErrores;
 *     if (class_exists(Integration::class) && ReporteDeErrores::vaADestinoPropio()) {
 *         Integration::handles($exceptions);
 *     }
 */
final class ReporteDeErrores
{
    /**
     * Dominios de servicios de terceros a los que no se reportan errores.
     *
     * Se compara por SUFIJO DE HOST, no con `str_contains`: `errores.sentry.io`
     * tiene que caer, y un hipotético `sentry.io.graneros.cl` —que contiene la
     * cadena pero es nuestro— no.
     *
     * **No es configurable a propósito.** Una lista que se puede acortar desde
     * un `.env` no es un candado: es un comentario. Si algún día aparece otro
     * destino extranjero, se agrega acá, en el paquete, y sube a los ocho
     * sistemas con un `composer update`.
     *
     * @var list<string>
     */
    private const AJENOS = ['sentry.io'];

    public static function vaADestinoPropio(): bool
    {
        $dsn = trim((string) config('sentry.dsn'));

        if ($dsn === '') {
            return false;
        }

        $host = parse_url($dsn, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            Log::warning('El DSN de reporte de errores no se puede interpretar; no se reportará nada.');

            return false;
        }

        $host = strtolower($host);

        foreach (self::AJENOS as $ajeno) {
            if ($host === $ajeno || str_ends_with($host, '.'.$ajeno)) {
                Log::warning(
                    'El DSN de reporte de errores apunta a un servicio de terceros; no se engancha. '
                    .'Las trazas de este sistema llevan datos personales y no pueden salir del país '
                    .'(Ley 21.719). Usá el GlitchTip municipal.',
                    ['host' => $host]
                );

                return false;
            }
        }

        return true;
    }
}
