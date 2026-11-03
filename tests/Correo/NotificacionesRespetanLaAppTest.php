<?php

declare(strict_types=1);

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\View;

/**
 * La plantilla de notificaciones del paquete (en español, con la despedida
 * institucional) va DETRÁS de la de la app y DELANTE de la del framework:
 * atencionvecino tiene la suya, que no muestra la URL del requerimiento en el
 * aviso de respaldo, y esa tiene que seguir mandando.
 */
function renderAviso(): string
{
    return (string) (new MailMessage)
        ->line('Su solicitud fue recibida.')
        ->action('Ver solicitud', 'https://sistema.example/solicitud/1')
        ->render();
}

/**
 *olvida las vistas ya resueltas para que la app vuelva a buscar la plantilla.
 *
 * Además de la vista y su finder, hay que olvidar el renderer de Markdown:
 * es un singleton que guarda la vista con la que se construyó, así que sin
 * esto seguiría buscando `notifications::email` en la vista anterior y la
 * prueba no probaría nada.
 */
function reiniciarVistas(): void
{
    app()->forgetInstance('view');
    app()->forgetInstance('view.finder');
    app()->forgetInstance(Markdown::class);
    View::clearResolvedInstance('view');
}

beforeEach(function () {
    config([
        'app.name' => 'Sistema de Prueba',
        'correo-institucional.sistema' => 'Licencias de Conducir',
    ]);
});

it('sin plantilla propia de la app, la notificación sale en español con la despedida institucional', function () {
    $html = renderAviso();

    expect($html)
        ->toContain('Saludos,')
        ->toContain('Licencias de Conducir')
        ->toContain('Si tiene problemas para hacer clic en el botón')
        ->not->toContain('Regards,')
        ->not->toContain('Sistema de Prueba');
});

it('si la app tiene su propia plantilla de notificaciones, gana la de la app', function () {
    $dir = resource_path('views/vendor/notifications');
    $archivo = $dir.'/email.blade.php';
    $creada = ! is_dir($dir);

    if ($creada) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($archivo, "<x-mail::message>\nPLANTILLA-DE-LA-APP\n</x-mail::message>\n");

    try {
        reiniciarVistas();

        $html = renderAviso();

        expect($html)
            ->toContain('PLANTILLA-DE-LA-APP')
            ->not->toContain('Si tiene problemas para hacer clic en el botón');
    } finally {
        @unlink($archivo);
        if ($creada) {
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
        reiniciarVistas();
    }
});
