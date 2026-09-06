<?php

declare(strict_types=1);

namespace Muni\Shared;

use Illuminate\Support\Facades\File;

/**
 * URLs de assets de ruta fija con versión automática.
 *
 * Portado desde `app/Helpers/assets.php`, que vivía en 7 de los 8 sistemas
 * (idéntico salvo un `declare(strict_types=1)` en licencias).
 *
 * La versión sale del `mtime` del archivo: cualquier cambio del archivo cambia
 * la URL y revienta el caché de todos los clientes sin ediciones manuales ni
 * esperar expiración. Es para los assets de ruta fija —imágenes, fuentes, css
 * de proveedor—; el bundle de Vite ya se versiona solo por hash de archivo.
 *
 * Si el archivo no existe se devuelve `?v=1` en vez de lanzar: una imagen que
 * falta no puede tumbar la página que la referencia.
 *
 * La función global `asset_versionado()` —que es lo que escriben las
 * plantillas— vive en `src/helpers.php` y delega acá.
 */
final class Assets
{
    public static function versionado(string $ruta): string
    {
        $archivo = public_path($ruta);
        $v = File::exists($archivo) ? (string) File::lastModified($archivo) : '1';

        return asset($ruta).'?v='.$v;
    }
}
