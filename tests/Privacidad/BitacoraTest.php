<?php

use Muni\Shared\Privacidad\BitacoraEnBaseDeDatos;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\ResultadoVerificacion;

it('resuelve la bitácora en base de datos como registro de evidencia por defecto', function () {
    expect(app(RegistroDeEvidencia::class))->toBeInstanceOf(BitacoraEnBaseDeDatos::class);
});

it('deja una entrada con el evento, el sistema y los datos', function () {
    config(['privacidad.sistema' => 'discapacidad']);

    app(RegistroDeEvidencia::class)->registrar('retencion.anonimizado', ['persona_id' => 7]);

    $entrada = EntradaBitacora::sole();

    expect($entrada->evento)->toBe('retencion.anonimizado')
        ->and($entrada->sistema)->toBe('discapacidad')
        ->and($entrada->datos)->toBe(['persona_id' => 7])
        ->and($entrada->ocurrido_en)->not->toBeNull();
});

it('el resultado de verificación conserva método y evidencia', function () {
    $resultado = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']);

    expect($resultado->verificado)->toBeTrue()
        ->and($resultado->metodo)->toBe('cedula_presencial')
        ->and($resultado->evidencia)->toBe(['run' => '11.111.111-1']);
});
