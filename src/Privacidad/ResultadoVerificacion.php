<?php

namespace Muni\Shared\Privacidad;

/**
 * @property-read array<string, mixed> $evidencia
 */
final class ResultadoVerificacion
{
    /** @param array<string, mixed> $evidencia */
    public function __construct(
        public readonly bool $verificado,
        public readonly string $metodo,
        public readonly array $evidencia = [],
    ) {}

    public static function fallida(string $metodo, string $motivo): self
    {
        return new self(false, $metodo, ['motivo' => $motivo]);
    }
}
