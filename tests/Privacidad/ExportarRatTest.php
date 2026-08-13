<?php

use Illuminate\Support\Facades\Artisan;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(function () {
    // Sin TTY (este entorno de test, CI) Symfony cae a 80 columnas y envuelve
    // las celdas de la tabla, partiendo textos como "Ley 20.422" en dos
    // líneas. Es una circunstancia del arnés de test, no del comando: se fija
    // acá y no en el comando, para no forzar un ancho de tabla arbitrario
    // sobre la sesión real de un funcionario municipal.
    $this->anchoOriginal = getenv('COLUMNS');
    putenv('COLUMNS=200');

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

afterEach(function () {
    putenv($this->anchoOriginal === false ? 'COLUMNS' : "COLUMNS={$this->anchoOriginal}");
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

it('exporta json válido con finalidades vacío cuando el sistema no declaró nada', function () {
    // Quien usa --json lo hace para pasarle la salida a json_decode: una
    // advertencia en texto plano en vez de JSON rompe a cualquier consumidor,
    // justo cuando el sistema más necesita quedar documentado (sin
    // finalidades declaradas).
    Finalidad::query()->delete();

    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $salida = Artisan::output();
    $rat = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);

    expect($rat['sistema'])->toBe('discapacidad')
        ->and($rat['responsable']['nombre'])->toBe('I. Municipalidad de Graneros')
        ->and($rat['finalidades'])->toBe([]);
});

it('exporta finalidades activas e inactivas por igual, distinguiendo el estado', function () {
    // El RAT no filtra por `activa`: una finalidad dada de baja sigue siendo
    // parte del historial de tratamiento y ocultarla sería una forma distinta
    // de tergiversar lo que el sistema hizo. Se muestra el estado, no se
    // recorta la lista.
    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'campana_censo_2019',
        'nombre' => 'Campaña de censo comunal 2019',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'activa' => false,
    ]);

    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $finalidades = collect($rat['finalidades'])->keyBy('codigo');

    expect($finalidades)->toHaveCount(2)
        ->and($finalidades['registro_comunal']['activa'])->toBeTrue()
        ->and($finalidades['campana_censo_2019']['activa'])->toBeFalse();
});
