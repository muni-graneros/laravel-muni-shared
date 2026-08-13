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

    /**
     * El motivo NO debe contener el RUN, el nombre ni ningún otro identificador
     * del solicitante: se interpola en el mensaje de `IdentidadNoVerificada`,
     * que termina en los logs de la aplicación —sin cifrar y con otra política
     * de retención—. «la cédula no coincide» sirve igual que «el RUN 11.111.111-1
     * no coincide», y no convierte un intento fallido de verificación en una
     * copia de datos personales fuera del módulo.
     */
    public static function fallida(string $metodo, string $motivo): self
    {
        return new self(false, $metodo, ['motivo' => $motivo]);
    }
}
