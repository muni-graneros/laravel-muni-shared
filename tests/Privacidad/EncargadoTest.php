<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Modelos\Encargado;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.responsable.nombre' => 'I. Municipalidad de Graneros']);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'registro_comunal', 'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);
});

it('asocia un encargado a una finalidad', function () {
    $encargado = Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Maestro de personas', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subMonth(), 'contrato_vence_en' => now()->addYear(),
    ]);

    $this->finalidad->encargados()->attach($encargado);

    expect($this->finalidad->fresh()->encargados)->toHaveCount(1)
        ->and($this->finalidad->fresh()->encargados->first()->nombre)->toBe('Maestro de personas');
});

it('la relación se recorre también desde el encargado', function () {
    // La inversa importa tanto como la directa: un encargado real trata datos
    // de varias finalidades, y el RAT lo cuenta una vez por cada una.
    $encargado = Encargado::create(['sistema' => 'discapacidad', 'nombre' => 'Maestro de personas', 'rol' => 'encargado']);
    $encargado->finalidades()->attach($this->finalidad);

    expect($encargado->fresh()->finalidades)->toHaveCount(1)
        ->and($encargado->fresh()->finalidades->first()->codigo)->toBe('registro_comunal');
});

it('detecta a los encargados sin contrato firmado', function () {
    Encargado::create(['sistema' => 'discapacidad', 'nombre' => 'Sin contrato', 'rol' => 'encargado']);

    expect(Encargado::sinContratoVigente()->pluck('nombre')->all())->toBe(['Sin contrato']);
});

it('detecta a los encargados con contrato vencido', function () {
    Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Vencido', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subYears(3), 'contrato_vence_en' => now()->subMonth(),
    ]);

    expect(Encargado::sinContratoVigente()->pluck('nombre')->all())->toBe(['Vencido']);
});

it('no marca al que tiene contrato al día', function () {
    Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Al día', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subMonth(), 'contrato_vence_en' => now()->addYear(),
    ]);

    expect(Encargado::sinContratoVigente()->count())->toBe(0);
});

it('un contrato sin fecha de vencimiento se considera vigente', function () {
    Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Indefinido', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subMonth(),
    ]);

    expect(Encargado::sinContratoVigente()->count())->toBe(0);
});

it('un encargado dado de baja no cuenta aunque su contrato esté vencido', function () {
    // Ya no trata datos de este sistema: su contrato vencido es historia, no
    // una alerta operativa que alguien tenga que ir a renovar.
    Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'De baja', 'rol' => 'encargado', 'activo' => false,
        'contrato_firmado_en' => now()->subYears(3), 'contrato_vence_en' => now()->subMonth(),
    ]);

    expect(Encargado::sinContratoVigente()->count())->toBe(0);
});

it('el RAT avisa de los encargados sin contrato al día', function () {
    Encargado::create(['sistema' => 'discapacidad', 'nombre' => 'Sin contrato', 'rol' => 'encargado']);

    $codigo = Artisan::call('privacidad:rat');

    expect($codigo)->toBe(0)
        ->and(Artisan::output())->toContain('sin contrato al día');
});

it('el RAT no avisa nada cuando todos los encargados están al día', function () {
    Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Al día', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subMonth(), 'contrato_vence_en' => now()->addYear(),
    ]);

    $codigo = Artisan::call('privacidad:rat');

    expect($codigo)->toBe(0)
        ->and(Artisan::output())->not->toContain('sin contrato al día');
});

it('el RAT en json incluye los encargados de cada finalidad', function () {
    $encargado = Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Maestro de personas', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subMonth(), 'contrato_vence_en' => now()->addYear(),
    ]);
    $this->finalidad->encargados()->attach($encargado);

    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['finalidades'][0]['encargados'])->toHaveCount(1)
        ->and($rat['finalidades'][0]['encargados'][0]['nombre'])->toBe('Maestro de personas')
        ->and($rat['finalidades'][0]['encargados'][0]['rol'])->toBe('encargado')
        ->and($rat['finalidades'][0]['encargados'][0]['contrato_vence_en'])->toBe(now()->addYear()->toDateString());
});

it('privacidad_encargados no tiene titular_id: el barrido de anonimización no la conoce ni tiene por qué', function () {
    // Confirmación ejecutable de la decisión de diseño, no solo un comentario:
    // esta tabla guarda un catálogo de organizaciones, no de titulares, así
    // que ni pertenece a Bitacora::TABLAS ni a las guardias de deriva de
    // AnonimizacionDelGrafoTest, que descubren las tablas barridas por esta
    // misma columna. Si algún día alguien le agrega `titular_id` —convirtiendo
    // el encargado en algo asociado a una persona puntual, que ya no sería
    // esta tabla— esas guardias se lo van a exigir a Bitacora sin que este
    // test tenga que enterarse.
    expect(Schema::hasColumn('privacidad_encargados', 'titular_id'))->toBeFalse()
        ->and(array_key_exists('privacidad_encargados', (new ReflectionClass(Bitacora::class))->getConstant('TABLAS')))->toBeFalse();
});

it('contrato_path no entra al borrado de archivos de la anonimización', function () {
    // El documento acredita el contrato con un TERCERO, no datos de un
    // titular: no tiene por qué —ni debe— borrarse cuando se anonimiza a un
    // vecino cualquiera. Confirmado contra la lista real y no contra la
    // intención: si alguien agrega la columna a ARCHIVOS sin revisar este
    // argumento, este test se pone rojo.
    $archivos = (new ReflectionClass(Bitacora::class))->getConstant('ARCHIVOS');

    expect($archivos)->not->toHaveKey('privacidad_encargados');
});
