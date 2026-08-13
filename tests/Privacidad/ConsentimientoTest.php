<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->difusion = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión en redes',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
    ]);
});

it('otorga un consentimiento vigente para una finalidad accesoria', function () {
    app(Consentimientos::class)->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);

    expect(app(Consentimientos::class)->vigente($this->titular, $this->difusion))->toBeTrue();
});

it('revocar no borra la evidencia: marca la fecha y deja de estar vigente', function () {
    $servicio = app(Consentimientos::class);
    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);

    $servicio->revocar($this->titular, $this->difusion);

    expect($servicio->vigente($this->titular, $this->difusion))->toBeFalse()
        ->and(Consentimiento::count())->toBe(1)
        ->and(Consentimiento::sole()->revocado_en)->not->toBeNull();
});

it('volver a otorgar tras una revocación deja un consentimiento vigente y conserva el anterior', function () {
    $servicio = app(Consentimientos::class);
    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);
    $servicio->revocar($this->titular, $this->difusion);

    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::VerbalRegistrada);

    expect(Consentimiento::count())->toBe(2)
        ->and($servicio->vigente($this->titular, $this->difusion))->toBeTrue();
});

it('no hay consentimiento vigente si nunca se otorgó', function () {
    expect(app(Consentimientos::class)->vigente($this->titular, $this->difusion))->toBeFalse();
});
