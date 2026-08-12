<?php

/*
|--------------------------------------------------------------------------
| Envío de correo por Microsoft Graph
|--------------------------------------------------------------------------
|
| Este bloque se fusiona en mail.mailers.graph, así que los sistemas del
| ecosistema NO tienen que tocar su config/mail.php: alcanza con poner
| MAIL_MAILER=graph y las credenciales en su .env.
|
| Acá no hay ningún secreto: son lecturas del .env de cada sistema, que no se
| versiona. El paquete aporta el código; las credenciales nunca salen de cada
| instalación.
|
| Se envía como aplicación registrada y no como persona. Las casillas
| municipales tienen segundo factor y SMTP con usuario y contraseña no sabe
| hacerlo: ese camino lo bloquea el propio Microsoft. La alternativa habría sido
| pedir una casilla exenta del segundo factor, o sea una cuenta municipal con
| contraseña y sin protección, de forma permanente.
|
*/

return [
    'transport' => 'graph',

    // Las tres credenciales son las MISMAS en todos los sistemas: salen de un
    // único registro de aplicación en Entra ID.
    'tenant' => env('MICROSOFT_GRAPH_TENANT_ID'),
    'cliente' => env('MICROSOFT_GRAPH_CLIENT_ID'),
    'secreto' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),

    // Esto sí cambia por sistema: la casilla desde la que sale el correo. Tiene
    // que estar dentro del grupo que autoriza la política de acceso, o Microsoft
    // rechaza el envío aunque la casilla exista.
    'remitente' => env('MICROSOFT_GRAPH_REMITENTE'),

    // El secreto vence en una fecha y Microsoft no avisa antes. Ese día todos
    // los sistemas dejan de mandar correo a la vez y, como el código para entrar
    // llega por correo, quedan todos cerrados. Anotarla es lo que permite avisar
    // con anticipación.
    'vence' => env('MICROSOFT_GRAPH_SECRET_VENCE'),
];
