<?php

declare(strict_types=1);

use Muni\Shared\Assets;

if (! function_exists('asset_versionado')) {
    /**
     * URL de asset con versión AUTOMÁTICA (mtime del archivo): cualquier cambio
     * del archivo cambia la URL y revienta el caché de todos los clientes sin
     * ediciones manuales ni esperar expiración. Para assets de ruta fija
     * (imágenes, fuentes, css de proveedor); el bundle de Vite ya se versiona
     * solo por hash de archivo.
     *
     * Es el mismo nombre que ya usan las plantillas Blade de siete sistemas, y
     * conserva la guarda `function_exists` del original: mientras dura la
     * adopción, el `app/Helpers/assets.php` del sistema y este archivo pueden
     * convivir sin un fatal por redeclaración. Toda la lógica está en
     * `Muni\Shared\Assets::versionado()`, que es lo que se prueba y lo que ve
     * PHPStan.
     */
    function asset_versionado(string $ruta): string
    {
        return Assets::versionado($ruta);
    }
}
