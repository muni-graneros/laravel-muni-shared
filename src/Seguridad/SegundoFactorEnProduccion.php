<?php

declare(strict_types=1);

namespace Muni\Shared\Seguridad;

use Illuminate\Support\Facades\App;

/**
 * En producción, el segundo factor no se apaga ni se regala.
 *
 * Los siete `config/mfa.php` del ecosistema son CINCO archivos distintos —
 * atencionvecino describe un TOTP con `emisor` y sin `show_code`,
 * control-acceso agrega el tope de códigos enviados, el resto difiere en la
 * redacción— y la configuración del segundo factor ya tiene dueño:
 * `laravel-muni-acceso` la expone como `acceso.mfa.*` leyendo las MISMAS
 * variables de entorno (`MFA_ENABLED`, `MFA_SHOW_CODE`). Por eso este paquete
 * NO trae un `config/mfa.php`: sería un segundo interruptor para la misma
 * cerradura, que es peor que la copia que veníamos a eliminar.
 *
 * Lo que sí comparten las siete variantes es una advertencia escrita en un
 * comentario —«en PRODUCCIÓN debe quedar en false»— que hoy no hace cumplir
 * nadie. Esta clase la hace cumplir, leyendo la configuración que exista:
 * `mfa.*` en los sistemas que todavía no adoptaron muni-acceso, `acceso.mfa.*`
 * en los que sí. No escribe configuración: solo la mira.
 *
 * Los dos vectores que cubre:
 *
 * - `show_code` encendido en producción pinta el código en la propia pantalla
 *   de verificación (y lo escribe en los logs): quien tenga usuario y
 *   contraseña ve el segundo factor y la MFA no protege nada.
 * - `enabled` apagado en producción deja el panel —con el nombre, el RUT y el
 *   domicilio de cada vecino— detrás de una contraseña sola.
 *
 * El interruptor solo se exige donde EXISTE: un sistema que no declara segundo
 * factor no tiene deriva que denunciar, tiene una decisión de diseño.
 */
final class SegundoFactorEnProduccion
{
    /**
     * Pares de claves equivalentes: la del sistema suelto y la de muni-acceso.
     *
     * @var list<string>
     */
    private const INTERRUPTORES = ['mfa.enabled', 'acceso.mfa.activa'];

    /**
     * @var list<string>
     */
    private const MUESTRAN_EL_CODIGO = ['mfa.show_code', 'acceso.mfa.mostrar_codigo'];

    /**
     * Lo que hay que arreglar, en castellano. Lista vacía = nada que objetar.
     *
     * @return list<string>
     */
    public static function problemas(?bool $enProduccion = null): array
    {
        $enProduccion ??= App::environment('production');

        if (! $enProduccion) {
            return [];
        }

        $problemas = [];

        foreach (self::INTERRUPTORES as $clave) {
            // `null` es «la clave no existe»: ese sistema no declara segundo
            // factor y no hay nada que exigirle.
            if (config($clave) !== null && config($clave) === false) {
                $problemas[] = "«{$clave}» está apagado con APP_ENV=production: el panel, que muestra "
                    .'nombre, RUT y domicilio de cada vecino, queda detrás de una contraseña sola.';
            }
        }

        foreach (self::MUESTRAN_EL_CODIGO as $clave) {
            if (config($clave) === true) {
                $problemas[] = "«{$clave}» está encendido con APP_ENV=production: el código del segundo "
                    .'factor se pinta en la propia pantalla de verificación y se escribe en los logs, '
                    .'así que cualquiera con usuario y contraseña lo ve. La MFA no protege nada.';
            }
        }

        return $problemas;
    }
}
