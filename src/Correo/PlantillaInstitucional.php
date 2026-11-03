<?php

namespace Muni\Shared\Correo;

/**
 * La plantilla institucional de correo: logo, franja, color y pie.
 *
 * Vive en el paquete y no en cada sistema porque los doce sistemas municipales
 * la necesitan y solo uno de ellos tenía copia. Cuando el logo y los colores
 * estaban en el `resources/views/vendor/mail` de cada app, actualizar el
 * paquete no cambiaba nada: el correo salía distinto según el sistema que lo
 * mandara, y el logo por URL no cargaba en los sistemas que solo se ven por
 * VPN.
 *
 * Aquí están las dos rutas que el resto del paquete necesita: dónde está el
 * PNG que se incrusta en el correo y dónde están las vistas que ganan sobre las
 * de cada app.
 */
final class PlantillaInstitucional
{
    /**
     * Content-ID del logo incrustado.
     *
     * Viaja como `cid:` en el HTML y como `Content-ID` en la parte MIME del
     * correo. No es una URL a propósito: el PNG va adjunto, así que se ve
     * aunque el sistema esté detrás de la VPN y el cliente no pueda cargar
     * nada externo.
     */
    public const CID = 'logo-municipalidad-graneros@muni-graneros';

    /**
     * Nombre del tema CSS de la plantilla (`mail::themes.graneros`).
     */
    public const TEMA = 'graneros';

    /**
     * Ruta absoluta del PNG del logo dentro del paquete.
     */
    public static function rutaLogo(): string
    {
        return dirname(__DIR__, 2).'/resources/images/correo/logo-municipalidad-graneros.png';
    }

    /**
     * Ruta absoluta de resources/views/mail del paquete (la que contiene html/ y text/).
     */
    public static function rutaComponentes(): string
    {
        return dirname(__DIR__, 2).'/resources/views/mail';
    }
}
