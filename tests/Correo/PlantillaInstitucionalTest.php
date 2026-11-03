<?php

declare(strict_types=1);

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as NotificacionBase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Muni\Shared\Correo\PlantillaInstitucional;
use Muni\Shared\SystemNotification;

/**
 * Todo correo Markdown de un sistema municipal sale con la plantilla institucional
 * del paquete: logo incrustado (cid:, nunca por URL: hay sistemas que solo se ven por
 * VPN), nombre del sistema, franja de siete colores en tabla, petróleo #355a63 y pie
 * institucional. Gana aunque la app tenga su propio vendor/mail u otro tema.
 */
function correoDePrueba(): MailMessage
{
    return (new MailMessage)
        ->subject('Asunto de prueba')
        ->greeting('Hola')
        ->line('Cuerpo del correo.')
        ->action('Entrar al sistema', 'https://sistema.example/entrar');
}

function franjaDe(string $html): array
{
    preg_match_all('/height:\s*5px;\s*background-color:\s*(#[0-9a-f]{6})/i', $html, $m);

    return $m[1];
}

final class NotificacionConLineas extends NotificacionBase
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return correoDePrueba();
    }
}

final class NotificacionConVista extends SystemNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->correo('Trámite recibido', 'correo-prueba::aviso', ['nombre' => 'Ana']);
    }
}

const FRANJA_INSTITUCIONAL = ['#adcd60', '#355a63', '#eab02c', '#c76421', '#7ccbe1', '#ca3048', '#9c9b9b'];
const BOTON_PETROLEO = '/button-primary[^>]*style="[^"]*background-color:\s*#355a63/i';

beforeEach(function () {
    config([
        'app.name' => 'Sistema de Prueba',
        'correo-institucional.sistema' => null,
        'correo-institucional.unidad' => null,
    ]);
    View::addNamespace('correo-prueba', __DIR__.'/fixtures');
});

it('un correo con ->line() sale con la cabecera, la franja, el botón y el pie institucionales', function () {
    $html = (string) correoDePrueba()->render();

    expect($html)
        ->toContain('src="cid:'.PlantillaInstitucional::CID.'"')
        ->toContain('alt="Municipalidad de Graneros"')
        ->toContain('width="240"')
        ->toContain('Sistema de Prueba')
        ->toContain('Cuerpo del correo.')
        ->toContain('Ilustre Municipalidad de Graneros')
        ->toContain('https://www.municipalidadgraneros.cl/')
        ->toContain('Este es un correo automático, por favor no lo respondas.')
        ->toMatch(BOTON_PETROLEO)
        ->not->toMatch('#<img[^>]+src="https?://#i');

    expect(franjaDe($html))->toBe(FRANJA_INSTITUCIONAL);
});

it('el nombre configurado del sistema manda sobre APP_NAME en la cabecera y el título, y la unidad aparece en el pie', function () {
    config([
        'correo-institucional.sistema' => 'Licencias de Conducir',
        'correo-institucional.unidad' => 'Dirección de Tránsito',
    ]);

    $html = (string) correoDePrueba()->render();

    expect($html)
        ->toContain('<title>Licencias de Conducir</title>')
        // La despedida la pone la plantilla de CONTENIDO —la del framework o la
        // de la app—, que el paquete ya no manda: ahí puede quedar el nombre
        // interno del sistema (APP_NAME). En las partes institucionales —la
        // cabecera y el título— manda el nombre configurado.
        ->not->toMatch('/Sistema de Prueba\s*<\/div>/')
        ->toContain('Licencias de Conducir')
        ->toContain('Dirección de Tránsito');
});

it('sin unidad no deja un separador huérfano en el pie', function () {
    $html = (string) correoDePrueba()->render();

    expect($html)->not->toMatch('/·\s*Ilustre Municipalidad de Graneros/u');
});

it('nunca muestra el logo de Laravel aunque la app se llame Laravel', function () {
    config(['app.name' => 'Laravel']);

    $html = (string) correoDePrueba()->render();

    expect($html)
        ->not->toContain('laravel.com')
        ->toContain('src="cid:'.PlantillaInstitucional::CID.'"');
});

it('escapa el nombre del sistema', function () {
    config(['correo-institucional.sistema' => '<script>alert(1)</script>']);

    $html = (string) correoDePrueba()->render();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
});

it('una SystemNotification con vista Markdown propia hereda la plantilla', function () {
    $html = (string) (new NotificacionConVista)->toMail(new AnonymousNotifiable)->render();

    expect($html)
        ->toContain('Hola Ana')
        ->toContain('src="cid:'.PlantillaInstitucional::CID.'"')
        ->toMatch(BOTON_PETROLEO);

    expect(franjaDe($html))->toBe(FRANJA_INSTITUCIONAL);
});

it('gana aunque la app tenga su propio vendor/mail y otro tema configurado', function () {
    $dir = sys_get_temp_dir().'/mail-app-'.bin2hex(random_bytes(4));
    mkdir($dir.'/html/themes', 0777, true);
    file_put_contents($dir.'/html/header.blade.php', '<tr><td>CABECERA-DE-LA-APP</td></tr>');
    file_put_contents($dir.'/html/themes/default.css', '.button-primary { background-color: #000000; }');

    try {
        config(['mail.markdown.paths' => [$dir], 'mail.markdown.theme' => 'default']);
        app()->forgetInstance(Markdown::class);

        $html = (string) correoDePrueba()->render();

        expect($html)
            ->not->toContain('CABECERA-DE-LA-APP')
            ->toContain('src="cid:'.PlantillaInstitucional::CID.'"')
            ->toMatch(BOTON_PETROLEO);
    } finally {
        @unlink($dir.'/html/header.blade.php');
        @unlink($dir.'/html/themes/default.css');
        @rmdir($dir.'/html/themes');
        @rmdir($dir.'/html');
        @rmdir($dir);
    }
});

it('el logo es un PNG opaco y liviano de 480x134', function () {
    $ruta = PlantillaInstitucional::rutaLogo();

    expect(is_file($ruta))->toBeTrue();

    [$ancho, $alto, $tipo] = getimagesize($ruta);

    expect($tipo)->toBe(IMAGETYPE_PNG)
        ->and($ancho)->toBe(480)
        ->and($alto)->toBe(134)
        ->and(filesize($ruta))->toBeLessThan(30_000);
});
