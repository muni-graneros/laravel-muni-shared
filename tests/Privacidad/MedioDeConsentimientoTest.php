<?php

use Muni\Shared\Privacidad\MedioDeConsentimiento;

it('etiqueta cada medio en español, la misma asimetría con TipoDeSolicitud que tenía EstadoDeSolicitud', function (MedioDeConsentimiento $medio, string $esperada) {
    expect($medio->etiqueta())->toBe($esperada);
})->with([
    [MedioDeConsentimiento::FirmaPapel, 'Firma en papel'],
    [MedioDeConsentimiento::FirmaDigital, 'Firma digital'],
    [MedioDeConsentimiento::VerbalRegistrada, 'Verbal, registrada'],
]);
