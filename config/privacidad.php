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

    // Plazo para notificar una brecha a la Agencia. Configurable porque debe
    // confirmarse contra el texto vigente y su reglamento antes de producción,
    // igual que el plazo de respuesta a las solicitudes.
    'plazo_notificacion_brecha_dias' => (int) env('PRIVACIDAD_PLAZO_NOTIFICACION_BRECHA_DIAS', 3),

    // Disco donde viven los documentos que el módulo referencia:
    // `privacidad_solicitudes.respuesta_path` (la respuesta escrita al titular)
    // y `privacidad_consentimientos.evidencia_path` (el consentimiento firmado).
    //
    // Existe porque al anonimizar hay que BORRAR esos archivos, no solo olvidar
    // dónde estaban: anular la ruta y dejar el PDF en disco convierte un dato
    // personal localizable en uno perdido, que sigue siendo dato personal y ya
    // no hay forma de encontrar para suprimirlo. Y el borrado no lo puede hacer
    // el adoptante «después», porque después de anular la columna nadie sabe
    // qué archivo era.
    //
    // Default 'local' —el disco privado de Laravel— y no 'public': el
    // consentimiento firmado de un vecino no se sirve por URL.
    'disco_evidencia' => env('PRIVACIDAD_DISCO_EVIDENCIA', 'local'),

    // Datos del responsable del tratamiento, que van en el RAT y en las
    // respuestas al titular. Por municipio, nunca hardcodeados.
    'responsable' => [
        'nombre' => env('PRIVACIDAD_RESPONSABLE', ''),
        'contacto' => env('PRIVACIDAD_CONTACTO', ''),
        'delegado' => env('PRIVACIDAD_DELEGADO', ''),
    ],
];
