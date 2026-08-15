<?php

use Muni\Shared\Privacidad\Modelos\TextoInformativo;
use Muni\Shared\Privacidad\TextoInmutable;
use Muni\Shared\Privacidad\Textos;

beforeEach(fn () => config(['privacidad.sistema' => 'discapacidad']));

it('publica la primera versión de un texto y la deja vigente', function () {
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'Sus datos se tratan para…');

    expect($texto->version)->toBe(1)
        ->and($texto->vigente_hasta)->toBeNull()
        ->and(app(Textos::class)->vigente('aviso_recoleccion')->is($texto))->toBeTrue();
});

it('publicar de nuevo crea una versión y cierra la anterior, sin borrarla', function () {
    $servicio = app(Textos::class);
    $primera = $servicio->publicar('aviso_recoleccion', 'Texto viejo');

    $segunda = $servicio->publicar('aviso_recoleccion', 'Texto nuevo');

    expect($segunda->version)->toBe(2)
        ->and($servicio->vigente('aviso_recoleccion')->is($segunda))->toBeTrue()
        ->and($primera->fresh()->vigente_hasta)->not->toBeNull()
        // La versión vieja sobrevive: es la prueba de qué aceptó quien la vio.
        ->and(TextoInformativo::count())->toBe(2);
});

it('sella el hash del contenido al publicar', function () {
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'Contenido exacto');

    expect($texto->hash)->toBe(hash('sha256', 'Contenido exacto'));
});

it('rechaza modificar el contenido de un texto ya publicado', function () {
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'Original');

    expect(fn () => $texto->update(['contenido' => 'Alterado']))->toThrow(TextoInmutable::class);
});

it('rechaza borrar un texto publicado', function () {
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'Original');

    expect(fn () => $texto->delete())->toThrow(TextoInmutable::class);
});

it('los textos de sistemas distintos no se pisan', function () {
    app(Textos::class)->publicar('aviso_recoleccion', 'De discapacidad');
    config(['privacidad.sistema' => 'licencias']);
    $otro = app(Textos::class)->publicar('aviso_recoleccion', 'De licencias');

    expect($otro->version)->toBe(1)
        ->and(app(Textos::class)->vigente('aviso_recoleccion')->contenido)->toBe('De licencias');
});

it('devuelve null cuando el código no existe', function () {
    expect(app(Textos::class)->vigente('inexistente'))->toBeNull();
});
