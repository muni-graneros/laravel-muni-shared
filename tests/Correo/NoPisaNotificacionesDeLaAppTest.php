<?php

declare(strict_types=1);

/**
 * La plantilla `notifications::email` es contenido de cada sistema (idioma, texto
 * del aviso de respaldo), no marca: atencionvecino la sobreescribe para no mostrar
 * la URL del requerimiento. El paquete pone la marca en `mail::message` y no debe
 * registrar ninguna ruta propia para `notifications`, que le ganaría a la de la app.
 */
it('el paquete no registra vistas propias para notifications::', function () {
    $paquete = realpath(dirname(__DIR__, 2));

    view()->make('notifications::email', [
        'level' => 'info',
        'introLines' => [],
        'outroLines' => [],
        'greeting' => null,
        'salutation' => null,
        'actionText' => null,
    ]);

    $rutas = app('view')->getFinder()->getHints()['notifications'] ?? [];

    foreach ($rutas as $ruta) {
        $real = realpath($ruta) ?: $ruta;
        expect(str_starts_with($real, $paquete.DIRECTORY_SEPARATOR.'resources'))->toBeFalse();
    }

    expect(is_dir($paquete.'/resources/views/notifications'))->toBeFalse();
});
