<?php

use Illuminate\Support\Facades\Artisan;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(function () {
    config([
        'privacidad.sistema' => 'discapacidad',
        'privacidad.responsable.nombre' => 'I. Municipalidad de Graneros',
    ]);

    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal de personas con discapacidad',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 120,
    ]);
});

it('imprime el RAT del sistema con su base de licitud y norma', function () {
    // Se usa Artisan::call()+output() y no la cadena
    // $this->artisan(...)->expectsOutputToContain(...): el mock de salida de
    // testing empareja cada línea escrita con UNA sola expectativa, y como
    // «registro_comunal» y «Ley 20.422» caen en la misma fila de la tabla,
    // la segunda expectativa nunca se marca como cumplida y el test falla
    // pese a que la tabla sí muestra ambos datos.
    $codigo = Artisan::call('privacidad:rat');

    expect($codigo)->toBe(0);

    $salida = Artisan::output();

    expect($salida)
        ->toContain('registro_comunal')
        ->toContain('Ley 20.422');
});

it('exporta el RAT en json con el responsable del tratamiento', function () {
    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $salida = Artisan::output();
    $rat = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);

    expect($rat['responsable']['nombre'])->toBe('I. Municipalidad de Graneros')
        ->and($rat['finalidades'][0]['codigo'])->toBe('registro_comunal')
        ->and($rat['finalidades'][0]['base_licitud'])->toBe('funcion_legal');
});

it('avisa cuando el sistema no declaró ninguna finalidad', function () {
    Finalidad::query()->delete();

    $codigo = Artisan::call('privacidad:rat');

    expect($codigo)->toBe(0)
        ->and(Artisan::output())->toContain('no declaró');
});
