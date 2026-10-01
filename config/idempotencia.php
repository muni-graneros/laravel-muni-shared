<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotencia de acciones POST (Muni\Shared\Http\Middleware\AccionIdempotente)
    |--------------------------------------------------------------------------
    |
    | Publicable con: php artisan vendor:publish --tag=idempotencia-config
    |
    */

    // Cuánto se recuerda la primera respuesta 2xx de una clave (segundos). 24 h.
    'ttl' => 86_400,

    // Prefijo de las claves de caché y de lock. Cámbialo si dos sistemas
    // comparten el mismo almacén de caché.
    'prefijo' => 'idempotencia',

    // Cuánto espera el lock de una clave en vuelo antes de responder 503 (s).
    'espera_del_lock' => 3,

    // Vida máxima del lock si el proceso muere sin liberarlo (s).
    'vida_del_lock' => 60,
];
