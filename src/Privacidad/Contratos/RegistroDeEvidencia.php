<?php

namespace Muni\Shared\Privacidad\Contratos;

use Illuminate\Database\Eloquent\Model;

interface RegistroDeEvidencia
{
    /** @param array<string, mixed> $datos */
    public function registrar(string $evento, array $datos, ?Model $titular = null): void;
}
