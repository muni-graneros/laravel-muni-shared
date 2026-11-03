<?php

declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as NotificacionBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Muni\Shared\Correo\PlantillaInstitucional;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class AvisoAlVecino extends NotificacionBase
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Aviso')
            ->line('Su solicitud fue recibida.')
            ->action('Ver solicitud', 'https://sistema.example/solicitud/1');
    }
}

/** @return Collection<int, Email> */
function correosEnviados(): Collection
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
        ->map(fn ($enviado) => $enviado->getOriginalMessage());
}

/** @return Collection<int, DataPart> */
function logosIncrustados(Email $email): Collection
{
    return collect($email->getAttachments())
        ->filter(fn (DataPart $parte) => $parte->hasContentId()
            && $parte->getContentId() === PlantillaInstitucional::CID);
}

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'mail.from' => ['address' => 'no-responder@municipalidadgraneros.cl', 'name' => 'Municipalidad de Graneros'],
        'app.name' => 'Sistema de Prueba',
    ]);
});

it('al enviar, el logo viaja como adjunto inline referenciado por cid', function () {
    Notification::route('mail', 'vecino@example.cl')->notifyNow(new AvisoAlVecino);

    $correos = correosEnviados();
    expect($correos)->toHaveCount(1);

    $email = $correos->first();
    $logos = logosIncrustados($email);

    expect($logos)->toHaveCount(1)
        ->and($logos->first()->getBody())->toBe(file_get_contents(PlantillaInstitucional::rutaLogo()))
        ->and($logos->first()->getMediaType().'/'.$logos->first()->getMediaSubtype())->toBe('image/png')
        ->and($email->getHtmlBody())->toContain('cid:'.PlantillaInstitucional::CID)
        ->and($email->getHtmlBody())->not->toMatch('#<img[^>]+src="https?://#i');

    expect($email->getTextBody())
        ->toContain('Municipalidad de Graneros')
        ->toContain('https://www.municipalidadgraneros.cl/')
        ->toContain('Su solicitud fue recibida.');
});

it('cada envío lleva exactamente un logo', function () {
    Notification::route('mail', 'a@example.cl')->notifyNow(new AvisoAlVecino);
    Notification::route('mail', 'b@example.cl')->notifyNow(new AvisoAlVecino);

    $correos = correosEnviados();
    expect($correos)->toHaveCount(2);

    $correos->each(fn (Email $email) => expect(logosIncrustados($email))->toHaveCount(1));
});

it('no adjunta el logo a un correo que no usa la plantilla', function () {
    Mail::html('<p>Reporte programado</p>', fn ($m) => $m->to('jefe@example.cl')->subject('Reporte'));

    $email = correosEnviados()->first();

    expect($email->getAttachments())->toBeEmpty();
});
