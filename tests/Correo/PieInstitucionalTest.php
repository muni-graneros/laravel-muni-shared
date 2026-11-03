<?php

declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;

it('el pie va en líneas separadas y con texto que cumple contraste AA', function () {
    config(['correo-institucional.unidad' => 'Dirección de Tránsito']);

    $html = (string) (new MailMessage)->line('Cuerpo.')->render();

    expect($html)
        ->toMatch('/Dirección de Tránsito · Ilustre Municipalidad de Graneros\s*<br/u')
        ->toMatch('#municipalidadgraneros\.cl/</a>\s*<br#')
        ->toMatch('/class="footer"[\s\S]*?color:\s*#52525b/i')
        ->not->toContain('#a1a1aa');
});
