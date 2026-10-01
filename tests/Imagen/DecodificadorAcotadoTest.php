<?php

declare(strict_types=1);

use Muni\Shared\Imagen\DecodificadorAcotado;
use Muni\Shared\Imagen\ImagenPreparada;

/*
 * Vector cubierto: bomba de descompresión (cabecera enorme, archivo chico) y
 * fuga de metadatos (EXIF/GPS) en fotos que sube un tercero.
 */
function imagenDePrueba(string $formato, int $ancho = 40, int $alto = 30): string
{
    $im = imagecreatetruecolor($ancho, $alto);
    imagefilledrectangle($im, 0, 0, $ancho - 1, $alto - 1, imagecolorallocate($im, 200, 30, 30));

    ob_start();
    match ($formato) {
        'png' => imagepng($im),
        'webp' => imagewebp($im),
        default => imagejpeg($im, null, 90),
    };

    return (string) ob_get_clean();
}

/** Inserta un segmento APP1/EXIF con un marcador reconocible justo tras el SOI. */
function jpegConExif(string $jpeg, string $secreto): string
{
    $carga = "Exif\0\0".$secreto;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($carga) + 2).$carga.substr($jpeg, 2);
}

beforeEach(fn () => DecodificadorAcotado::reiniciarContador());

it('re-codifica sin EXIF y calcula el sha256 de los bytes resultantes', function () {
    $original = jpegConExif(imagenDePrueba('jpeg'), 'GPS-34.06,-70.72');
    expect($original)->toContain('GPS-34.06');

    $img = DecodificadorAcotado::preparar($original, maximoPixeles: 10_000);

    expect($img)->toBeInstanceOf(ImagenPreparada::class)
        ->and($img->binario)->not->toContain('GPS-34.06')
        ->and($img->extension)->toBe('jpg')
        ->and($img->mime)->toBe('image/jpeg')
        ->and($img->sha256)->toBe(hash('sha256', $img->binario))
        ->and($img->sha256)->not->toBe(hash('sha256', $original))
        ->and(getimagesizefromstring($img->binario)[0])->toBe(40);
});

it('conserva png y webp con su extensión', function (string $formato, string $extension) {
    $img = DecodificadorAcotado::preparar(imagenDePrueba($formato), 10_000);

    expect($img->extension)->toBe($extension);
})->with([['png', 'png'], ['webp', 'webp']]);

it('decodifica UNA sola vez por imagen', function () {
    DecodificadorAcotado::preparar(imagenDePrueba('png'), 10_000);
    expect(DecodificadorAcotado::decodificacionesHechas())->toBe(1);

    DecodificadorAcotado::preparar(imagenDePrueba('jpeg'), 10_000);
    expect(DecodificadorAcotado::decodificacionesHechas())->toBe(2);
});

it('rechaza por la cabecera lo que excede el tope de píxeles, SIN decodificar', function () {
    // 200×100 = 20.000 px contra un tope de 10.000: el tope es por llamada.
    $grande = imagenDePrueba('png', 200, 100);

    expect(fn () => DecodificadorAcotado::preparar($grande, maximoPixeles: 10_000))
        ->toThrow(InvalidArgumentException::class, 'píxeles')
        ->and(DecodificadorAcotado::decodificacionesHechas())->toBe(0);

    // La misma imagen pasa con un tope mayor: el límite lo decide quien llama.
    expect(DecodificadorAcotado::preparar($grande, maximoPixeles: 20_000)->extension)->toBe('png');
});

it('rechaza lo que pesa más que el tope de bytes', function () {
    expect(fn () => DecodificadorAcotado::preparar(imagenDePrueba('png'), 10_000, maximoBytes: 10))
        ->toThrow(InvalidArgumentException::class, 'pesa')
        ->and(DecodificadorAcotado::decodificacionesHechas())->toBe(0);
});

it('rechaza lo que no es una imagen aceptada (texto, SVG, HTML con cabecera falsa)', function (string $contenido) {
    expect(fn () => DecodificadorAcotado::preparar($contenido, 10_000))
        ->toThrow(InvalidArgumentException::class)
        ->and(DecodificadorAcotado::decodificacionesHechas())->toBe(0);
})->with([
    'texto' => 'hola',
    'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'html' => '<html><body>GIF89a</body></html>',
]);

it('un archivo truncado falla limpio y no deja buffers de salida abiertos', function () {
    $nivel = ob_get_level();
    $png = imagenDePrueba('png', 100, 100);

    expect(fn () => DecodificadorAcotado::preparar(substr($png, 0, 30), 1_000_000))
        ->toThrow(InvalidArgumentException::class)
        ->and(ob_get_level())->toBe($nivel);
});

it('rechaza formatos que GD sí sabe leer pero no están en la lista (GIF, también animado, y BMP)', function (string $binario) {
    // getimagesizefromstring() y GD aceptan estos formatos: lo único que los frena es
    // la lista de tipos por contenido. Sin ella, un GIF animado entraría al pipeline.
    expect(getimagesizefromstring($binario))->not->toBeFalse();

    expect(fn () => DecodificadorAcotado::preparar($binario, 10_000))
        ->toThrow(InvalidArgumentException::class, 'no es una imagen aceptada')
        ->and(DecodificadorAcotado::decodificacionesHechas())->toBe(0);
})->with([
    'gif' => fn () => (function () {
        ob_start();
        imagegif(imagecreatetruecolor(10, 10));

        return (string) ob_get_clean();
    })(),
    'gif animado' => fn () => "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff"
        ."\x21\xff\x0bNETSCAPE2.0\x03\x01\x00\x00\x00"
        ."\x21\xf9\x04\x00\x0a\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00"
        ."\x21\xf9\x04\x00\x0a\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x4c\x01\x00\x3b",
    'bmp' => fn () => (function () {
        ob_start();
        imagebmp(imagecreatetruecolor(10, 10));

        return (string) ob_get_clean();
    })(),
]);

it('un PNG con chunks de texto (tEXt) sale sin ellos', function () {
    $png = imagenDePrueba('png');
    $datos = "Comment\0vecino-rut-12345678-5";
    $chunk = pack('N', strlen($datos)).'tEXt'.$datos.pack('N', crc32('tEXt'.$datos));
    // Tras la firma (8 bytes) y el IHDR (25 bytes).
    $conTexto = substr($png, 0, 33).$chunk.substr($png, 33);
    expect($conTexto)->toContain('vecino-rut');

    $img = DecodificadorAcotado::preparar($conTexto, 10_000);

    expect($img->binario)->not->toContain('vecino-rut')
        ->and($img->extension)->toBe('png');
});
