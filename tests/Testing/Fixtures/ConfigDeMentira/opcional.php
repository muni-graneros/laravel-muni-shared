<?php

/**
 * Fixture de tests/Testing/ContratoDeEnvExampleTest.php: una bandera opcional,
 * del mismo tipo que CSP_ENABLED u OCR_ENABLED en los sistemas reales, que
 * .env.example documenta COMENTADA (ver `env-example-de-mentira/.env.example`).
 * Comentada sigue siendo documentación: no tiene que reportarse como faltante.
 */
return [
    'activo' => env('MODO_EXPERIMENTAL', false),
];
