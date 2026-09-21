<?php

declare(strict_types=1);

namespace Muni\Shared\Seguridad;

use RuntimeException;

/**
 * Impide que las credenciales de ejemplo lleguen a producción.
 *
 * Nace de `App\Support\CredencialesDePlantilla` en `scaffold-laravel-filament-pwa`
 * (ver ese archivo: el docblock original explica el problema con más detalle). El
 * `.env.example` de cada sistema trae una contraseña de base de datos —y otras,
 * como la de cifrado de los respaldos— para que un proyecto recién generado
 * levante sin configurar nada. Es cómodo y es correcto: el contenedor de
 * desarrollo no está expuesto a nadie.
 *
 * El problema es el día que alguien despliega sin cambiarlas. El scaffold es
 * público, así que esos valores los conoce cualquiera que haya visto el
 * repositorio: en producción dejan de ser una comodidad y pasan a ser una
 * puerta abierta con la llave puesta. La auditoría del 2026-09-05 encontró que
 * esta guarda vivía SOLO en el scaffold: los ocho sistemas generados a partir de
 * él no tienen la clase, y su `.env.example` sigue trayendo el mismo valor.
 *
 * Por eso vive acá, en el paquete compartido, y el arranque de cada sistema la
 * llama sola —ver `MuniSharedServiceProvider::boot()`—: no es un paso que un
 * adoptante tenga que acordarse de escribir. El arranque en producción falla si
 * encuentra alguno de estos valores, y falla diciendo exactamente qué variable
 * del `.env` cambiar, nunca el valor real que encontró.
 */
final class CredencialesDePlantilla
{
    /**
     * Los valores por omisión: los que trae el `.env.example` del scaffold.
     *
     * Cada fila es [qué es, dónde vigilarlo (config, con el nombre de la
     * conexión activa resuelto en tiempo de ejecución si aplica), el valor de
     * plantilla, y la variable del .env que hay que cambiar].
     *
     * Un sistema con sus propias credenciales de plantilla —o con más de dos—
     * publica `config/credenciales-de-plantilla.php` y arma su propia lista
     * a partir de esta, por ejemplo:
     *
     *     use Muni\Shared\Seguridad\CredencialesDePlantilla;
     *
     *     return [
     *         'valores' => [
     *             ...CredencialesDePlantilla::POR_OMISION,
     *             [
     *                 'queEs' => 'contraseña del panel de reportes',
     *                 'config' => 'reportes.password',
     *                 'valorDePlantilla' => 'reportes_demo',
     *                 'variableEnv' => 'REPORTES_PASSWORD',
     *             ],
     *         ],
     *     ];
     *
     * @var list<array{queEs: string, config: string, valorDePlantilla: string, variableEnv: string}>
     */
    public const array POR_OMISION = [
        [
            'queEs' => 'contraseña de la base de datos',
            'config' => 'database.connections.{conexion}.password',
            'valorDePlantilla' => 'sistema_pass',
            'variableEnv' => 'DB_PASSWORD',
        ],
        [
            'queEs' => 'contraseña de cifrado de los respaldos',
            'config' => 'backup.backup.password',
            'valorDePlantilla' => 'cambiame-por-algo-seguro-en-produccion',
            'variableEnv' => 'BACKUP_ARCHIVE_PASSWORD',
        ],
    ];

    public static function comprobar(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        // Durante la instalación todavía no hay «.env»: `composer install`
        // dispara `package:discover`, que arranca la aplicación con los valores
        // por defecto —y el de APP_ENV es «production»—. Sin clave de aplicación
        // no hay instalación real que proteger, y sin esta guarda el candado
        // tumbaba el propio `composer install`, en el CI y en el build de la
        // imagen: el error hablaba de la contraseña de la base y despistaba.
        // Copiada tal cual de `App\Support\CredencialesDePlantilla` del
        // scaffold: es la salvaguarda que ya costó una tarde de ecosistema.
        if ((string) config('app.key') === '') {
            return;
        }

        $conexion = (string) config('database.default');

        foreach (self::valores() as $fila) {
            $ruta = str_replace('{conexion}', $conexion, $fila['config']);
            $valorActual = (string) config($ruta);

            if ($valorActual === $fila['valorDePlantilla']) {
                throw new RuntimeException(
                    "La {$fila['queEs']} sigue siendo la del ejemplo («{$fila['valorDePlantilla']}»), y este ".
                    'sistema está corriendo en producción. Ese valor está publicado en el repositorio del '.
                    "scaffold, así que lo conoce cualquiera. Cambia {$fila['variableEnv']} en el .env del ".
                    'servidor antes de seguir.',
                );
            }
        }
    }

    /**
     * La lista efectiva: la que publicó el sistema, o los valores por omisión
     * si nunca publicó `config/credenciales-de-plantilla.php`.
     *
     * @return list<array{queEs: string, config: string, valorDePlantilla: string, variableEnv: string}>
     */
    private static function valores(): array
    {
        /** @var list<array{queEs: string, config: string, valorDePlantilla: string, variableEnv: string}> */
        return (array) config('credenciales-de-plantilla.valores', self::POR_OMISION);
    }
}
