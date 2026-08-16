<?php

use Muni\Shared\Privacidad\Informaciones;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\InformacionEntregada;
use Muni\Shared\Privacidad\OpcionInvalida;
use Muni\Shared\Privacidad\TextoNoPublicado;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->texto = app(Textos::class)->publicar('aviso_recoleccion', 'Sus datos se tratan para…');
});

it('sella qué versión exacta vio el titular', function () {
    $registro = app(Informaciones::class)
        ->registrar($this->titular, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel);

    expect($registro->texto_id)->toBe($this->texto->getKey())
        ->and($registro->entregado_en)->not->toBeNull()
        ->and(app(Informaciones::class)->seInformo($this->titular, 'aviso_recoleccion'))->toBeTrue();
});

it('sigue apuntando a la versión vieja aunque después se publique otra', function () {
    app(Informaciones::class)->registrar($this->titular, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel);

    app(Textos::class)->publicar('aviso_recoleccion', 'Texto nuevo');

    // Lo que importa acreditar es qué leyó ELLA, no qué dice el texto de hoy.
    expect(InformacionEntregada::sole()->texto_id)->toBe($this->texto->getKey());
});

it('sella la fila que se mostró cuando el adoptante la pasa, aunque ya no sea la vigente', function () {
    // La misma carrera del consentimiento, en el camino hermano: entre que el
    // formulario se renderiza y que se guarda, otro publica. Resolver el código
    // al escribir sellaba «se informó» con un texto que el titular no vio.
    $leido = $this->texto;
    $nuevo = app(Textos::class)->publicar('aviso_recoleccion', 'Versión nueva');

    $registro = app(Informaciones::class)->registrar(
        $this->titular, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel,
        ['texto' => $leido],
    );

    expect($registro->texto_id)->toBe($leido->getKey())
        ->and($registro->texto_id)->not->toBe($nuevo->getKey());
});

it('rechaza un texto cuyo código no es el que se dice haber informado', function () {
    $otro = app(Textos::class)->publicar('aviso_camaras', 'Otro aviso');

    expect(fn () => app(Informaciones::class)->registrar(
        $this->titular, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel,
        ['texto' => $otro],
    ))->toThrow(OpcionInvalida::class);

    expect(InformacionEntregada::count())->toBe(0);
});

it('rechaza informar con un código que nadie publicó', function () {
    expect(fn () => app(Informaciones::class)
        ->registrar($this->titular, 'inexistente', MedioDeConsentimiento::FirmaPapel))
        ->toThrow(TextoNoPublicado::class);
});

it('no deja rastro cuando el texto no existe', function () {
    rescue(fn () => app(Informaciones::class)
        ->registrar($this->titular, 'inexistente', MedioDeConsentimiento::FirmaPapel));

    expect(InformacionEntregada::count())->toBe(0);
});

it('deja constancia en la bitácora sin volcar el contenido del texto', function () {
    app(Informaciones::class)->registrar($this->titular, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel);

    $entrada = EntradaBitacora::where('evento', 'informacion.entregada')->sole();

    expect($entrada->datos)->toHaveKey('codigo')
        ->and($entrada->datos)->not->toHaveKey('contenido');
});

it('sabe que no se informó cuando no hay registro', function () {
    expect(app(Informaciones::class)->seInformo($this->titular, 'aviso_recoleccion'))->toBeFalse();
});
