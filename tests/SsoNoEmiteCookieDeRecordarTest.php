<?php

/**
 * El acceso federado no reparte cookies de «Recordarme».
 *
 * El callback del SSO hacía «Auth::login($user, remember: true)» siempre, así
 * que cada ingreso por Keycloak dejaba en el navegador un testigo de catorce
 * meses. Los sistemas del ecosistema ya descartan esa cookie antes de que el
 * guard la vea, pero seguía viajando en cada respuesta y valiendo en la base.
 *
 * El candado mira el código fuente porque la batería del paquete no ejercita
 * el intercambio de testigos con Keycloak: sin esto, reintroducirlo no rompe
 * nada visible y vuelve a los ocho sistemas que consumen el paquete.
 */
it('el acceso federado no pide la cookie de recordar', function () {
    $fuente = (string) file_get_contents(__DIR__.'/../src/Sso/KeycloakSsoController.php');

    expect($fuente)->not->toMatch('/remember:\s*true/')
        ->and($fuente)->not->toMatch('/Auth::login\(\s*\$\w+\s*,/');
});
