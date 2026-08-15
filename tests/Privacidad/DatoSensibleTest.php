<?php

use Illuminate\Support\Facades\Artisan;
use Muni\Shared\Privacidad\BaseLicitud;
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
        'categorias_datos' => ['identificacion', 'salud'],
    ])))->toThrow(FinalidadInvalida::class);
});

it('acepta la misma finalidad cuando declara la causal', function () {
    $finalidad = Finalidad::create(finalidadBase([
        'categorias_datos' => ['identificacion', 'salud'],
        'excepcion_dato_sensible' => ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey,
    ]));

    expect($finalidad->exists)->toBeTrue()
        ->and($finalidad->excepcion_dato_sensible)->toBe(ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey);
});

it('no exige causal cuando ninguna categoría es sensible', function () {
    $finalidad = Finalidad::create(finalidadBase([
        'codigo' => 'agenda',
        'categorias_datos' => ['identificacion', 'contacto'],
    ]));

    expect($finalidad->exists)->toBeTrue()
        ->and($finalidad->excepcion_dato_sensible)->toBeNull();
});

it('reconoce como sensible la situación socioeconómica, que es propia de la ley chilena', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'codigo' => 'ficha_social',
        'categorias_datos' => ['socioeconomico'],
    ])))->toThrow(FinalidadInvalida::class);
});

it('el RAT en json expone la causal', function () {
    config(['privacidad.sistema' => 'discapacidad']);
    Finalidad::create(finalidadBase([
        'categorias_datos' => ['salud'],
        'excepcion_dato_sensible' => ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey,
    ]));

    Artisan::call('privacidad:rat --json');
    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['finalidades'][0]['excepcion_dato_sensible'])->toBe('fines_estatales_habilitados_por_ley');
});
