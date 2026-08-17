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

    // Disco donde el SISTEMA ADOPTANTE guarda los documentos cuyas rutas el
    // módulo almacena: `privacidad_solicitudes.respuesta_path` (la respuesta
    // escrita al titular) y `privacidad_consentimientos.evidencia_path` (el
    // consentimiento firmado).
    //
    // Quién escribe qué, porque acá decía lo contrario y de ahí salía el
    // razonamiento equivocado: el módulo NUNCA escribió esos archivos. Las dos
    // rutas llegan de afuera —`Solicitudes::acoger($respuestaPath)` y
    // `Consentimientos::otorgar(['evidencia_path' => …])`—, las subió el sistema
    // adoptante al disco que él eligió, y la única llamada a `Storage` en todo
    // `src/` es el borrado al anonimizar. O sea que esta clave no describe dónde
    // guarda el módulo: es el adoptante DECLARÁNDOLE al módulo dónde buscar.
    //
    // Existe porque al anonimizar hay que BORRAR esos archivos, no solo olvidar
    // dónde estaban: anular la ruta y dejar el PDF en disco convierte un dato
    // personal localizable en uno perdido, que sigue siendo dato personal y ya
    // no hay forma de encontrar para suprimirlo. Y el borrado no lo puede hacer
    // el adoptante «después», porque después de anular la columna nadie sabe
    // qué archivo era. Por eso configurarla es paso obligatorio de adopción y
    // está en el README.
    //
    // Sin default, y antes lo tenía ('local'), con un comentario que decía que
    // dejarla vacía «revienta el barrido». Es falso, comprobado ejecutando —no
    // leyendo— las tres formas de no configurarla: `env()` solo aplica el
    // default cuando la clave está AUSENTE del entorno, así que
    // `PRIVACIDAD_DISCO_EVIDENCIA=` (presente y vacía) da `''`, no 'local'. Y
    // `Storage::disk('')` no truena: cae en `getDefaultDriver()` en silencio,
    // lo mismo que hacía cuando la clave estaba ausente y el default 'local' SÍ
    // se aplicaba. Las tres rutas —vacía, ausente, apuntando a un disco real
    // que no es donde vive el documento— terminaban en el mismo sitio: el
    // barrido busca en un disco, no encuentra nada, lo cuenta como
    // `archivos_no_encontrados` y sigue. Un sistema adoptante lo sufrió así: la
    // clave nunca llegó a su `.env` ni a su `.env.example`, cayó en el default
    // 'local' sin que nadie lo decidiera, y sus documentos vivían en otro
    // disco.
    //
    // Por qué no un default 'plausible' en su lugar (algún disco que existiera
    // siempre): acá un default no es una convención de nombres como en
    // `sistema` de arriba —ahí el riesgo es un marcador de posición que PARECE
    // un nombre real y tarda en notarse—. Acá cualquier default resuelve a un
    // disco que EXISTE, así que la operación nunca truena por sí sola: sigue
    // corriendo, borra o no borra según la suerte de que el adoptante también
    // use ese mismo disco, y el único síntoma queda enterrado en un conteo de
    // la constancia que hay que ir a buscar. Un default aquí no reduce el
    // riesgo de mala configuración, lo esconde mejor.
    //
    // Por eso el módulo ahora se niega a barrer hasta que el adoptante declare
    // el disco: `Bitacora::resolverDisco()` lanza `DiscoEvidenciaNoConfigurado`
    // apenas encuentra un documento que borrar y esta clave está en blanco.
    // (Un nombre que no resuelve a un disco configurado ya fallaba fuerte —
    // `Storage::disk()` lanza `InvalidArgumentException`—; lo que faltaba
    // cubrir era la cadena vacía, indistinguible de «no configurado» solo para
    // quien lee el `.env`, no para `env()`.) La clave queda en el mismo
    // régimen que `sistema`: sin default. La diferencia es que acá el efecto
    // de no configurarla no es un aviso por consola, es una excepción que
    // aborta la anonimización, porque lo que está en juego no es un rótulo en
    // un reporte sino un documento con datos personales que deja de ser
    // localizable.
    //
    // Lo que este cambio NO cierra, para no volver a prometer de más: un
    // nombre de disco que SÍ resuelve —existe en `filesystems.disks`— pero no
    // es donde el adoptante guarda los documentos sigue sin poder detectarlo
    // el módulo por su cuenta. Ese caso no truena, porque no hay nada
    // técnicamente mal configurado; sigue siendo la señal de
    // `archivos_no_encontrados` alto con `archivos_suprimidos` en cero en la
    // constancia `retencion.constancia`.
    'disco_evidencia' => env('PRIVACIDAD_DISCO_EVIDENCIA'),

    // Registrar una rectificación u oposición suspende el tratamiento hasta
    // resolverla. Configurable porque frena la operación del mesón: revisarlo
    // con la jefatura antes de producción.
    'bloquear_durante_solicitud' => (bool) env('PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD', true),

    'retencion' => [
        // Hora diaria a la que corre `privacidad:aplicar-retencion --ejecutar`,
        // en formato 'HH:MM'. Vacío o ausente = NO se agenda.
        //
        // Sin default a propósito, y esta vez el default ausente no es sobre
        // configuración sino sobre destrucción: un paquete que se instala y
        // empieza a anonimizar por cron en ocho sistemas —porque alguien corrió
        // `composer update`— es inaceptable, por más que la obligación legal de
        // suprimir sí exista. La decisión de cuándo se destruye es del
        // municipio.
        //
        // Y el otro lado del mismo problema, que es el que se midió: en el
        // sistema real el módulo estaba instalado, migrado, sembrado y con los
        // contratos enlazados, y `schedule:list` no lo listaba. Nunca corrió.
        // Por eso poner esta clave es paso OBLIGATORIO de adopción y está en el
        // README: la alternativa —agendarlo a mano en cada sistema— es
        // exactamente el paso que nadie escribió.
        'hora' => env('PRIVACIDAD_RETENCION_HORA'),

        // Cada cuántos titulares se publica la constancia acumulada de la
        // corrida. No es afinamiento: es lo que hace que una corrida
        // interrumpida deje evidencia de lo que alcanzó a hacer. La corrida real
        // murió por timeout con 10.131 personas anonimizadas y cero constancias.
        'lote' => (int) env('PRIVACIDAD_RETENCION_LOTE', 100),

        // Vigencia del candado que impide dos corridas simultáneas. Tiene que
        // ser mayor que la corrida más larga esperable: la real iba a ~17
        // personas por segundo. Se suelta igual al terminar; esto es el techo
        // para el caso en que el proceso muera sin soltarlo.
        'candado_segundos' => (int) env('PRIVACIDAD_RETENCION_CANDADO_SEGUNDOS', 21600),
    ],

    // Datos del responsable del tratamiento, que van en el RAT y en las
    // respuestas al titular. Por municipio, nunca hardcodeados.
    'responsable' => [
        'nombre' => env('PRIVACIDAD_RESPONSABLE', ''),
        'contacto' => env('PRIVACIDAD_CONTACTO', ''),
        'delegado' => env('PRIVACIDAD_DELEGADO', ''),
    ],
];
