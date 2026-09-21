<?php

/**
 * Fixture de tests/Testing/ContratoDeEnvExampleTest.php. NO es config real del
 * paquete: existe solo para ejercitar ContratoDeEnvExample contra las tres
 * trampas que tiene que sortear sin confundirse.
 */
return [
    // env('LEGACY_TOKEN') quedó comentado al migrar el servicio: no se usa, y
    // por eso NO tiene que exigirse en .env.example.
    'maestro' => [
        'url' => env('MAESTRO_URL'),
        'token' => env('MAESTRO_TOKEN', 'valor-de-fabrica'),
    ],
    // Nunca una llamada real a la función: el nombre solo aparece dentro de un
    // string de ayuda. Confundirlo con una llamada real haría reportar
    // «CADENA_NO_ES_CLAVE» como faltante, un falso positivo que nadie puede
    // arreglar en .env.example porque no es una clave.
    'ayuda' => "Configura con env('CADENA_NO_ES_CLAVE') en el shell del sistema.",
];
