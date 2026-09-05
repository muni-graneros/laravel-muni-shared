<?php

use Muni\Shared\Seguridad\CredencialesDePlantilla;

return [
    // Los valores que este sistema NO puede tener en producción. Por omisión,
    // los del `.env.example` del scaffold («sistema_pass», la contraseña de
    // cifrado de los respaldos): son públicos —cualquiera que vio el
    // repositorio los conoce— y por eso hace falta esta guarda.
    //
    // Un sistema con sus propias credenciales de plantilla —o con más de dos—
    // publica este archivo (`--tag=credenciales-de-plantilla-config`) y arma su
    // propia lista a partir de `CredencialesDePlantilla::POR_OMISION`, para no
    // perder la vigilancia sobre las del scaffold al sumar la suya. Ver el
    // docblock de la clase.
    'valores' => CredencialesDePlantilla::POR_OMISION,
];
