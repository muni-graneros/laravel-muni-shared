<?php

return [
    // Identifica al sistema dentro del RAT compartido del ecosistema.
    //
    // Sin default plausible a propósito: el valor llega a salida que se le
    // muestra a la autoridad («RAT del sistema ...»), y un marcador de posición
    // que parece un nombre real pasa desapercibido mucho más tiempo que un
    // vacío. Los comandos avisan cuando no está configurado.
    'sistema' => env('PRIVACIDAD_SISTEMA'),

    // Plazo legal de respuesta a una solicitud ARCOP. Configurable porque debe
    // confirmarse contra el texto vigente y su reglamento antes de producción.
    'plazo_respuesta_dias' => (int) env('PRIVACIDAD_PLAZO_RESPUESTA_DIAS', 30),

    // Datos del responsable del tratamiento, que van en el RAT y en las
    // respuestas al titular. Por municipio, nunca hardcodeados.
    'responsable' => [
        'nombre' => env('PRIVACIDAD_RESPONSABLE', ''),
        'contacto' => env('PRIVACIDAD_CONTACTO', ''),
        'delegado' => env('PRIVACIDAD_DELEGADO', ''),
    ],
];
