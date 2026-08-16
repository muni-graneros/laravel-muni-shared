<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\OpcionInvalida;
use Muni\Shared\Privacidad\TextoNoPublicado;
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
        ['texto' => $this->texto],
    );

    expect($consentimiento->texto_id)->toBe($this->texto->getKey())
        ->and($consentimiento->texto->contenido)->toBe('Autorizo la difusión…');
});

it('acepta también el id del texto, que es lo que viaja en un formulario', function () {
    $consentimiento = app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        // Como vuelve del request: string, no entero.
        ['texto' => (string) $this->texto->getKey()],
    );

    expect($consentimiento->texto_id)->toBe($this->texto->getKey());
});

it('acredita el texto que se mostró, aunque entretanto se haya publicado otro', function () {
    // El defecto que esto cierra: entre que el formulario se renderiza y que el
    // funcionario lo guarda, otro publica una versión nueva. Resolver el código
    // al escribir dejaba el consentimiento apuntando a un texto que el titular
    // NUNCA vio, y eso es prueba falsa, no ausencia de prueba.
    $leido = $this->texto;

    $nuevo = app(Textos::class)->publicar('consentimiento_difusion', 'Versión NUEVA, que nadie leyó');

    $consentimiento = app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['texto' => $leido],
    );

    expect($consentimiento->texto_id)->toBe($leido->getKey())
        ->and($consentimiento->texto_id)->not->toBe($nuevo->getKey())
        ->and($consentimiento->texto->contenido)->toBe('Autorizo la difusión…');
});

it('el consentimiento sigue apuntando a la versión vieja tras publicar una nueva', function () {
    app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['texto' => $this->texto],
    );

    app(Textos::class)->publicar('consentimiento_difusion', 'Texto distinto');

    expect(Consentimiento::sole()->texto->contenido)->toBe('Autorizo la difusión…');
});

it('un texto inexistente se rechaza en vez de guardar null en silencio', function () {
    expect(fn () => app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['texto' => 9999],
    ))->toThrow(TextoNoPublicado::class);

    expect(Consentimiento::query()->count())->toBe(0);
});

it('un texto de otro sistema se rechaza: el RAT no es compartido a nivel de fila', function () {
    config(['privacidad.sistema' => 'licencias']);
    $ajeno = app(Textos::class)->publicar('consentimiento_difusion', 'Texto de otro sistema');
    config(['privacidad.sistema' => 'discapacidad']);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['texto' => $ajeno],
    ))->toThrow(TextoNoPublicado::class);
});

it('ya no acepta el código del texto: la fila que se mostró, o nada', function () {
    // Se rechaza en vez de ignorarse: quien escribió la opción creía estar
    // acreditando qué leyó el titular, y quedarse callado lo deja creyéndolo.
    expect(fn () => app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['codigo_texto' => 'consentimiento_difusion'],
    ))->toThrow(OpcionInvalida::class);

    expect(Consentimiento::query()->count())->toBe(0);
});

it('ya no acepta version_texto: era la constancia desacreditada', function () {
    expect(fn () => app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['version_texto' => 'v3'],
    ))->toThrow(OpcionInvalida::class);
});

it('sigue permitiendo otorgar sin texto, para los consentimientos en papel previos', function () {
    $consentimiento = app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
    );

    expect($consentimiento->exists)->toBeTrue()
        ->and($consentimiento->texto_id)->toBeNull()
        ->and($consentimiento->version_texto)->toBeNull();
});

it('un otorgado_por que no es del enum sale como excepción del módulo, no como ValueError', function () {
    expect(fn () => app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['otorgado_por' => 'tutor'],
    ))->toThrow(OpcionInvalida::class);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['otorgado_por' => ['representante_legal']],
    ))->toThrow(OpcionInvalida::class);
});
