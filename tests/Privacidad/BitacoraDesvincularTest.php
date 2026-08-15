<?php

use Illuminate\Support\Str;
use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->otro = PersonaDePrueba::create(['nombre' => 'Otro Titular', 'documento' => '22.222.222-2']);

    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['a' => 1], $this->titular);
    app(RegistroDeEvidencia::class)->registrar('solicitud.acogida', ['b' => 2], $this->titular);
    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['c' => 3], $this->otro);
});

it('corta el vínculo con el titular pero conserva las entradas', function () {
    $desvinculadas = app(Bitacora::class)->desvincular($this->titular);

    expect($desvinculadas)->toBe(2)
        // Las tres siguen ahí, más la que registra la propia desvinculación.
        ->and(EntradaBitacora::count())->toBe(4);

    $delTitular = EntradaBitacora::whereIn('evento', ['solicitud.registrada', 'solicitud.acogida'])
        ->whereNull('titular_id')->get();

    expect($delTitular)->toHaveCount(2)
        ->and($delTitular->pluck('titular_ref')->unique())->toHaveCount(1)
        ->and($delTitular->first()->titular_ref)->not->toBeNull();
});

it('las entradas del mismo titular siguen agrupables entre sí', function () {
    app(Bitacora::class)->desvincular($this->titular);

    $ref = EntradaBitacora::whereNull('titular_id')->whereNotNull('titular_ref')->first()->titular_ref;

    expect(EntradaBitacora::where('titular_ref', $ref)->count())->toBe(2);
});

it('no toca las entradas de otros titulares', function () {
    app(Bitacora::class)->desvincular($this->titular);

    $ajena = EntradaBitacora::where('titular_id', $this->otro->getKey())->sole();

    expect($ajena->titular_ref)->toBeNull()
        ->and($ajena->evento)->toBe('solicitud.registrada');
});

it('la referencia es aleatoria: dos titulares distintos nunca comparten una', function () {
    app(Bitacora::class)->desvincular($this->titular);
    app(Bitacora::class)->desvincular($this->otro);

    expect(EntradaBitacora::whereNotNull('titular_ref')->pluck('titular_ref')->unique())
        ->toHaveCount(2);
});

it('la referencia no codifica el instante de la anonimización', function () {
    // Un ULID —lo que este campo usaba antes— lleva la marca de tiempo en sus
    // 10 primeros caracteres, y ese instante se junta con el updated_at que
    // anonimizar() estampa en la persona. La referencia tiene que ser opaca,
    // no ordenable.
    app(Bitacora::class)->desvincular($this->titular);

    $ref = EntradaBitacora::whereNotNull('titular_ref')->first()->titular_ref;

    expect(Str::isUlid($ref))->toBeFalse()
        ->and(Str::isUuid($ref))->toBeFalse()
        ->and(strlen($ref))->toBe(32);
});

it('deja constancia de la propia desvinculación', function () {
    app(Bitacora::class)->desvincular($this->titular);

    expect(EntradaBitacora::where('evento', 'bitacora.desvinculada')->count())->toBe(1);
});
