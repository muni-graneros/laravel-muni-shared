<?php

declare(strict_types=1);

namespace Muni\Shared\Imagen;

use GdImage;
use InvalidArgumentException;

/**
 * Decodifica una imagen NO CONFIABLE (subida por un ciudadano o por una app) con
 * costo acotado, y la devuelve re-codificada sin metadatos.
 *
 * Vector cubierto: bomba de descompresión. `imagecreatefromstring()` reserva
 * memoria según ancho × alto de la imagen DECODIFICADA, no según el peso del
 * archivo: un PNG gris de 20000×20000 pesa unos 400 KB y le cuesta a GD cientos de
 * MB y segundos; `memory_limit` no lo frena porque esa memoria la reserva GD. Por eso:
 *
 * 1. Tope de bytes del binario (antes de mirar nada).
 * 2. Tipo MIME REAL por contenido (`finfo`), nunca el que declara el cliente. Sólo
 *    JPEG, PNG y WebP: nada de SVG (puede llevar scripts).
 * 3. `getimagesizefromstring()` lee sólo la cabecera, sin reservar píxeles: rechaza
 *    lo que excede el tope de megapíxeles o está truncado SIN pagar la decodificación.
 * 4. Una sola decodificación (`imagecreatefromstring`) y una sola recodificación. El
 *    resultado (sin EXIF) y su sha256 viajan en `ImagenPreparada`; la `GdImage` se
 *    suelta en el acto para no retener memoria mientras se escribe.
 *
 * El tope de píxeles lo decide quien llama (un endpoint público debería ser más
 * estricto que un panel con sesión y permiso).
 *
 *     $img = DecodificadorAcotado::preparar($bytes, maximoPixeles: 12_000_000);
 *     Storage::disk('privado')->put("f/{$id}.{$img->extension}", $img->binario);
 */
final class DecodificadorAcotado
{
    public const MAXIMO_BYTES_POR_DEFECTO = 8_388_608;

    /** Lo único que se acepta. Nada de SVG. */
    private const FORMATOS = ['image/jpeg', 'image/png', 'image/webp'];

    private static int $decodificaciones = 0;

    /** Cuántas veces este proceso le pidió a GD decodificar. Para que un test mida «una vez por imagen». */
    public static function decodificacionesHechas(): int
    {
        return self::$decodificaciones;
    }

    public static function reiniciarContador(): void
    {
        self::$decodificaciones = 0;
    }

    /**
     * @param  int  $maximoPixeles  ancho × alto máximo aceptado, mirado en la cabecera antes de GD
     *
     * @throws InvalidArgumentException si no es una imagen aceptada o excede los topes
     */
    public static function preparar(
        string $binario,
        int $maximoPixeles,
        int $maximoBytes = self::MAXIMO_BYTES_POR_DEFECTO,
    ): ImagenPreparada {
        if (strlen($binario) > $maximoBytes) {
            throw new InvalidArgumentException('La imagen pesa demasiado.');
        }

        $mime = self::tipoReal($binario);

        if ($mime === null) {
            throw new InvalidArgumentException('El archivo no es una imagen aceptada.');
        }

        $dimensiones = @getimagesizefromstring($binario);

        if ($dimensiones === false) {
            throw new InvalidArgumentException('La imagen no se pudo leer.');
        }

        if ($maximoPixeles < $dimensiones[0] * $dimensiones[1]) {
            throw new InvalidArgumentException('La imagen tiene demasiados píxeles.');
        }

        self::$decodificaciones++;
        $imagen = @imagecreatefromstring($binario);

        if ($imagen === false) {
            throw new InvalidArgumentException('La imagen no se pudo leer.');
        }

        ['binario' => $limpio, 'extension' => $extension, 'mime' => $mimeSalida] = self::sinMetadatos($imagen, $mime);

        return new ImagenPreparada($limpio, $extension, $mimeSalida, hash('sha256', $limpio));
    }

    /**
     * @return array{binario: string, extension: string, mime: string}
     */
    private static function sinMetadatos(GdImage $imagen, string $mime): array
    {
        // Buffer propio en try/finally: si el codificador lanzara, un worker de larga
        // vida (Octane) se quedaría con el buffer abierto y contaminaría la respuesta
        // de la petición siguiente. Se compara contra el nivel previo, no contra 0:
        // el runner de tests también tiene buffers y no hay que cerrar uno ajeno.
        $nivelAntes = ob_get_level();
        ob_start();

        try {
            if ($mime === 'image/png') {
                imagealphablending($imagen, false);
                imagesavealpha($imagen, true);
                imagepng($imagen, null, 6);
                $salida = ['png', 'image/png'];
            } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
                imagewebp($imagen, null, 88);
                $salida = ['webp', 'image/webp'];
            } else {
                imagejpeg($imagen, null, 88);
                $salida = ['jpg', 'image/jpeg'];
            }

            $binario = (string) ob_get_clean();
        } finally {
            if (ob_get_level() > $nivelAntes) {
                ob_end_clean();
            }
        }

        if ($binario === '') {
            throw new InvalidArgumentException('La imagen no se pudo volver a codificar.');
        }

        return ['binario' => $binario, 'extension' => $salida[0], 'mime' => $salida[1]];
    }

    private static function tipoReal(string $binario): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            // Sin fileinfo no se puede verificar el contenido, y creerle al cliente
            // es justamente lo que esta clase existe para evitar.
            throw new InvalidArgumentException('No se pudo verificar la imagen.');
        }

        $tipo = finfo_buffer($finfo, $binario);

        return in_array($tipo, self::FORMATOS, true) ? $tipo : null;
    }
}
