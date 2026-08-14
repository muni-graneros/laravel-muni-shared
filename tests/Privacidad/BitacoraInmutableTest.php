<?php

use Muni\Shared\Privacidad\BitacoraInmutable;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    app(RegistroDeEvidencia::class)->registrar('prueba.evento', ['campo' => 'valor']);
    $this->entrada = EntradaBitacora::sole();
});

it('rechaza modificar una entrada ya escrita', function () {
    expect(fn () => $this->entrada->update(['evento' => 'otro.evento']))
        ->toThrow(BitacoraInmutable::class);
});

it('rechaza borrar una entrada', function () {
    expect(fn () => $this->entrada->delete())->toThrow(BitacoraInmutable::class);
});

it('la entrada sigue intacta después de los intentos', function () {
    rescue(fn () => $this->entrada->update(['evento' => 'otro.evento']));
    rescue(fn () => $this->entrada->delete());

    expect(EntradaBitacora::count())->toBe(1)
        ->and(EntradaBitacora::sole()->evento)->toBe('prueba.evento');
});

it('sigue permitiendo escribir entradas nuevas', function () {
    app(RegistroDeEvidencia::class)->registrar('otro.evento', []);

    expect(EntradaBitacora::count())->toBe(2);
});
