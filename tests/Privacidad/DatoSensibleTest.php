<?php

use Illuminate\Support\Facades\Artisan;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\CategoriaDato;
use Muni\Shared\Privacidad\ExcepcionDatoSensible;
use Muni\Shared\Privacidad\FinalidadInvalida;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/** @param array<string, mixed> $extra */
function finalidadBase(array $extra = []): array
{
    return array_merge([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
    ], $extra);
}

it('rechaza una finalidad con datos sensibles que no declara la causal', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'categorias_datos' => [CategoriaDato::Identificacion, CategoriaDato::Salud],
    ])))->toThrow(FinalidadInvalida::class);
});

it('acepta la misma finalidad cuando declara la causal', function () {
    $finalidad = Finalidad::create(finalidadBase([
        'categorias_datos' => [CategoriaDato::Identificacion, CategoriaDato::Salud],
        'excepcion_dato_sensible' => ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey,
    ]));

    expect($finalidad->exists)->toBeTrue()
        ->and($finalidad->excepcion_dato_sensible)->toBe(ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey);
});

it('no exige causal cuando ninguna categoría es sensible', function () {
    $finalidad = Finalidad::create(finalidadBase([
        'codigo' => 'agenda',
        'categorias_datos' => [CategoriaDato::Identificacion, CategoriaDato::Contacto],
    ]));

    expect($finalidad->exists)->toBeTrue()
        ->and($finalidad->excepcion_dato_sensible)->toBeNull();
});

it('reconoce como sensible la situación socioeconómica, que es propia de la ley chilena', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'codigo' => 'ficha_social',
        'categorias_datos' => [CategoriaDato::Socioeconomico],
    ])))->toThrow(FinalidadInvalida::class);
});

it('reconoce como sensible la discapacidad: es la categoría central del registro que el módulo protege', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'codigo' => 'registro_discapacidad',
        'categorias_datos' => [CategoriaDato::Discapacidad],
    ])))->toThrow(FinalidadInvalida::class);
});

it('acepta la discapacidad cuando declara la causal', function () {
    $finalidad = Finalidad::create(finalidadBase([
        'codigo' => 'registro_discapacidad',
        'categorias_datos' => [CategoriaDato::Discapacidad],
        'excepcion_dato_sensible' => ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey,
    ]));

    expect($finalidad->exists)->toBeTrue();
});

it('rechaza una categoría de datos que no está en el vocabulario, en vez de tratarla como no sensible', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'codigo' => 'con_typo',
        'categorias_datos' => ['etnia'],
    ])))->toThrow(FinalidadInvalida::class, '«etnia» no es una categoría de datos reconocida');
});

it('rechaza una variante en mayúsculas de una categoría válida, en vez de dejarla pasar en silencio', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'codigo' => 'con_mayuscula',
        'categorias_datos' => ['Salud'],
    ])))->toThrow(FinalidadInvalida::class, '«Salud» no es una categoría de datos reconocida');
});

it('el mensaje de categoría no reconocida lista los valores válidos', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'codigo' => 'con_typo',
        'categorias_datos' => ['sindicato'],
    ])))->toThrow(FinalidadInvalida::class, 'afiliacion');
});

it('la válvula de escape «otro» nunca es no sensible: exige la causal igual que cualquier categoría sensible', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'codigo' => 'categoria_no_anticipada',
        'categorias_datos' => [CategoriaDato::Otro],
    ])))->toThrow(FinalidadInvalida::class);

    $finalidad = Finalidad::create(finalidadBase([
        'codigo' => 'categoria_no_anticipada',
        'categorias_datos' => [CategoriaDato::Otro],
        'excepcion_dato_sensible' => ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey,
    ]));

    expect($finalidad->exists)->toBeTrue();
});

it('el RAT en json expone la causal', function () {
    config(['privacidad.sistema' => 'discapacidad']);
    Finalidad::create(finalidadBase([
        'categorias_datos' => [CategoriaDato::Salud],
        'excepcion_dato_sensible' => ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey,
    ]));

    Artisan::call('privacidad:rat --json');
    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['finalidades'][0]['excepcion_dato_sensible'])->toBe('fines_estatales_habilitados_por_ley')
        ->and($rat['finalidades'][0]['categorias_datos'])->toBe(['salud']);
});
