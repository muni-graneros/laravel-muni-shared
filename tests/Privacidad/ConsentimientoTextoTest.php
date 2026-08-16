<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        // Adulta: desde el régimen de NNA, otorgar() exige la edad acreditada.
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
    $this->texto = app(Textos::class)->publicar('consentimiento_difusion', 'Autorizo la difusión…');
});

it('guarda a qué texto exacto se dio el consentimiento', function () {
    $consentimiento = app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['codigo_texto' => 'consentimiento_difusion'],
    );

    expect($consentimiento->texto_id)->toBe($this->texto->getKey())
        ->and($consentimiento->texto->contenido)->toBe('Autorizo la difusión…');
});

it('el consentimiento sigue apuntando a la versión vieja tras publicar una nueva', function () {
    app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['codigo_texto' => 'consentimiento_difusion'],
    );

    app(Textos::class)->publicar('consentimiento_difusion', 'Texto distinto');

    expect(Consentimiento::sole()->texto->contenido)->toBe('Autorizo la difusión…');
});

it('sigue permitiendo otorgar sin texto, para los consentimientos en papel previos', function () {
    $consentimiento = app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
    );

    expect($consentimiento->exists)->toBeTrue()
        ->and($consentimiento->texto_id)->toBeNull();
});
