<?php

/*
|--------------------------------------------------------------------------
| Correo institucional
|--------------------------------------------------------------------------
|
| La plantilla del correo institucional (logo, franja, color y pie) la pone
| este paquete, no cada sistema. Estos tres valores son lo único que cada
| sistema puede cambiar: qué nombre va bajo el logo, qué unidad aparece en el
| pie y a qué sitio institucional apunta el enlace.
|
| Sin esto, cada sistema que reinstala el paquete vuelve a poner su propio
| `resources/views/vendor/mail` y la Institutionalidad vuelve a verse de
| doce formas distintas.
|
*/

return [
    // Nombre del sistema bajo el logo. Vacío → config('app.name').
    'sistema' => env('MUNI_CORREO_SISTEMA'),

    // Unidad municipal opcional del pie, p. ej. "Dirección de Seguridad Pública".
    'unidad' => env('MUNI_CORREO_UNIDAD'),

    'sitio' => 'https://www.municipalidadgraneros.cl/',
];
