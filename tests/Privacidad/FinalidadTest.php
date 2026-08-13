<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\FinalidadInvalida;
use Muni\Shared\Privacidad\Modelos\Finalidad;

it('guarda una finalidad fundada en función legal con su norma habilitante', function () {
    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal de personas con discapacidad',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422, art. 1',
        'es_accesoria' => false,
        'categorias_datos' => ['identificacion', 'salud'],
        'destinatarios' => ['maestro_personas'],
    ]);

    expect($finalidad->exists)->toBeTrue()
        ->and($finalidad->base_licitud)->toBe(BaseLicitud::FuncionLegal)
        ->and($finalidad->categorias_datos)->toBe(['identificacion', 'salud']);
});

it('rechaza una finalidad sin base de licitud en vez de reventar con un error de PHP', function () {
    expect(fn () => Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'sin_base',
        'nombre' => 'Sin base de licitud',
        'es_accesoria' => false,
    ]))->toThrow(FinalidadInvalida::class);
});

it('rechaza una finalidad de función legal sin norma habilitante', function () {
    expect(fn () => Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'sin_norma',
        'nombre' => 'Sin norma',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'es_accesoria' => false,
    ]))->toThrow(FinalidadInvalida::class);
});

it('rechaza una finalidad accesoria que no se funde en el consentimiento', function () {
    expect(fn () => Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión en redes',
        'base_licitud' => BaseLicitud::InteresLegitimo,
        'es_accesoria' => true,
    ]))->toThrow(FinalidadInvalida::class);
});

it('sabe qué finalidades exigen consentimiento del titular', function () {
    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'es_accesoria' => false,
    ]);
    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión en redes',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
    ]);

    expect(Finalidad::accesorias()->pluck('codigo')->all())->toBe(['difusion']);
});
