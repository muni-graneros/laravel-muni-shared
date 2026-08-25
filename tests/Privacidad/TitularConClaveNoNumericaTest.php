<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\VecinoConRutDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'atencionvecino', 'privacidad.plazo_respuesta_dias' => 30]);

    Finalidad::create([
        'sistema' => 'atencionvecino', 'codigo' => 'requerimientos', 'nombre' => 'Requerimientos',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 18.695',
    ]);

    $this->vecino = VecinoConRutDePrueba::create([
        'rut' => '11111111-1',
        'nombre' => 'Rocío Paredes',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
});

it('un titular identificado por RUT queda apuntado tal cual, sin truncarse', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->vecino,
        TipoDeSolicitud::Acceso,
        'Pide copia de sus requerimientos.',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );

    // Lo que rompía: MariaDB truncaba «11111111-1» a 11111111 en una columna
    // bigint, y el expediente quedaba apuntando a un titular que no era.
    expect((string) $solicitud->fresh()->getAttribute('titular_id'))->toBe('11111111-1');
});

it('la solicitud vuelve a encontrar a su titular', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->vecino,
        TipoDeSolicitud::Acceso,
        'Pide copia de sus requerimientos.',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );

    $titular = Solicitud::findOrFail($solicitud->getKey())->titular;

    expect($titular)->not->toBeNull()
        ->and($titular->titularDocumento())->toBe('11111111-1');
});

it('la bitácora de la recepción también apunta al RUT', function () {
    app(Solicitudes::class)->registrar(
        $this->vecino,
        TipoDeSolicitud::Acceso,
        'Pide copia de sus requerimientos.',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );

    $entrada = EntradaBitacora::query()
        ->where('evento', 'solicitud.registrada')
        ->sole();

    expect((string) $entrada->getAttribute('titular_id'))->toBe('11111111-1');
});
