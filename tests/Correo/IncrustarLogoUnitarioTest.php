<?php

declare(strict_types=1);

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Muni\Shared\Correo\IncrustarLogoInstitucional;
use Muni\Shared\Correo\PlantillaInstitucional;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

function emailConLogo(): Email
{
    return (new Email)
        ->from('no-responder@municipalidadgraneros.cl')
        ->to('vecino@example.cl')
        ->subject('Aviso')
        ->html('<img src="cid:'.PlantillaInstitucional::CID.'" alt="Municipalidad de Graneros">');
}

function contarLogos(Email $email): int
{
    return collect($email->getAttachments())
        ->filter(fn (DataPart $p) => $p->hasContentId() && $p->getContentId() === PlantillaInstitucional::CID)
        ->count();
}

it('sobre el mismo Email, incrustar dos veces deja un solo logo', function () {
    $email = emailConLogo();
    $listener = new IncrustarLogoInstitucional;

    $listener->handle(new MessageSending($email));
    $listener->handle(new MessageSending($email));

    expect(contarLogos($email))->toBe(1);
});

it('si el logo no está, el correo sigue y el aviso no lleva datos del destinatario', function () {
    Log::spy();

    $email = emailConLogo();

    (new IncrustarLogoInstitucional('/no/existe/logo.png'))->handle(new MessageSending($email));

    expect($email->getAttachments())->toBeEmpty();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $mensaje, array $contexto = []) => ! str_contains($mensaje.json_encode($contexto), 'vecino@example.cl'));
});
