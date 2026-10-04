<?php

declare(strict_types=1);

namespace Muni\Shared\Imagen;

/**
 * Una imagen ya validada y re-codificada sin metadatos (EXIF, GPS, miniaturas
 * incrustadas): `binario` es exactamente lo que se puede escribir a disco y
 * `sha256` la huella de ESOS bytes, no del original subido.
 */
final readonly class ImagenPreparada
{
    public function __construct(
        public string $binario,
        public string $extension,
        public string $mime,
        public string $sha256,
    ) {}
}
