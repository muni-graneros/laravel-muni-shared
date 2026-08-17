<?php

use DomainException;
use Muni\Shared\Privacidad\EdadNoAcreditada;
use Muni\Shared\Privacidad\FinalidadInvalida;
use Muni\Shared\Privacidad\IdentidadNoVerificada;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\OpcionInvalida;
use Muni\Shared\Privacidad\RepresentacionNoAcreditada;
use Muni\Shared\Privacidad\RepresentacionRequerida;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitante;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\SolicitudRechazada;
use Muni\Shared\Privacidad\TextoNoPublicado;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;
use RuntimeException;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->verificacion = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']);
});

it('las cuatro negativas de Solicitudes::registrar() implementan SolicitudRechazada', function (string $clase) {
    expect(is_a($clase, SolicitudRechazada::class, true))->toBeTrue();
})->with([
    IdentidadNoVerificada::class,
    EdadNoAcreditada::class,
    RepresentacionNoAcreditada::class,
    RepresentacionRequerida::class,
]);

it('un catch por el tipo antiguo las sigue atrapando: no se tocó su extends', function () {
    // IdentidadNoVerificada seguía siendo RuntimeException antes de esta
    // interfaz; quien la atrapaba así no puede dejar de hacerlo.
    expect(new IdentidadNoVerificada('x'))->toBeInstanceOf(RuntimeException::class);

    // Las otras tres seguían siendo DomainException.
    expect(new EdadNoAcreditada('x'))->toBeInstanceOf(DomainException::class)
        ->and(new RepresentacionNoAcreditada('x'))->toBeInstanceOf(DomainException::class)
        ->and(new RepresentacionRequerida('x'))->toBeInstanceOf(DomainException::class);
});

it('un solo catch(SolicitudRechazada) atrapa cualquiera de las cuatro negativas reales del servicio', function () {
    $titular = PersonaDePrueba::create([
        'nombre' => 'Sin fecha',
        'documento' => '33.333.333-3',
    ]);

    $atrapada = null;

    try {
        // Identidad no verificada: la primera de las cuatro que puede lanzar.
        app(Solicitudes::class)->registrar(
            $titular,
            TipoDeSolicitud::Acceso,
            'Quiero mis datos',
            ResultadoVerificacion::fallida('ninguno', 'no presentó cédula'),
        );
    } catch (SolicitudRechazada $e) {
        $atrapada = $e;
    }

    expect($atrapada)->toBeInstanceOf(IdentidadNoVerificada::class);

    $atrapada = null;

    try {
        // Edad sin acreditar: la segunda negativa posible, con identidad OK.
        app(Solicitudes::class)->registrar(
            $titular, TipoDeSolicitud::Acceso, 'Quiero mis datos', $this->verificacion,
        );
    } catch (SolicitudRechazada $e) {
        $atrapada = $e;
    }

    expect($atrapada)->toBeInstanceOf(EdadNoAcreditada::class)
        ->and(Solicitud::query()->count())->toBe(0);
});

it('FinalidadInvalida, OpcionInvalida y TextoNoPublicado no son SolicitudRechazada', function () {
    // No son una negativa a la petición de un titular: son el módulo
    // rechazando una llamada mal armada del sistema adoptante. Mezclarlas
    // haría que un panel las mostrara como si fueran una razón legal.
    expect(is_a(FinalidadInvalida::class, SolicitudRechazada::class, true))->toBeFalse()
        ->and(is_a(OpcionInvalida::class, SolicitudRechazada::class, true))->toBeFalse()
        ->and(is_a(TextoNoPublicado::class, SolicitudRechazada::class, true))->toBeFalse();
});

it('los mensajes de las cuatro negativas no cambiaron al sumar la interfaz', function () {
    // El panel de Task 2 muestra $e->getMessage() sin tocar; esto fija que
    // agregar `implements SolicitudRechazada` no alteró ni un carácter.
    $sinFecha = PersonaDePrueba::create(['nombre' => 'Sin fecha', 'documento' => '44.444.444-4']);
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor', 'documento' => '55.555.555-5', 'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);
    $adulta = PersonaDePrueba::create([
        'nombre' => 'Adulta', 'documento' => '66.666.666-6', 'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    expect(fn () => app(Solicitudes::class)->registrar(
        $sinFecha,
        TipoDeSolicitud::Acceso,
        'x',
        ResultadoVerificacion::fallida('ninguno', 'no presentó cédula'),
    ))->toThrow(
        IdentidadNoVerificada::class,
        'No se puede registrar la solicitud: la identidad del solicitante no fue verificada (no presentó cédula).',
    );

    expect(fn () => app(Solicitudes::class)->registrar(
        $sinFecha, TipoDeSolicitud::Acceso, 'x', $this->verificacion,
    ))->toThrow(
        EdadNoAcreditada::class,
        'No se puede tramitar la solicitud sin saber si el titular es mayor de edad: este sistema no '
        .'tiene su fecha de nacimiento. Acreditarla con el documento correspondiente y reintentar.',
    );

    expect(fn () => app(Solicitudes::class)->registrar(
        $nna, TipoDeSolicitud::Acceso, 'x', $this->verificacion,
    ))->toThrow(
        RepresentacionRequerida::class,
        'Los derechos de un menor de edad los ejerce su representante legal, no él mismo ni un '
        .'apoderado suyo: un menor no puede otorgar mandato. Registrar la solicitud con el '
        .'representante legal y el documento que acredite serlo.',
    );

    expect(fn () => app(Solicitudes::class)->registrar(
        $adulta, TipoDeSolicitud::Acceso, 'x', $this->verificacion, Solicitante::Apoderado,
    ))->toThrow(
        RepresentacionNoAcreditada::class,
        'La solicitud la presenta «Apoderado con mandato» y no se acompañó el documento que '
        .'acredita la representación. Adjuntarlo y pasar su ruta: entregar o borrar los datos de '
        .'una persona a pedido de un tercero sin ese papel es la fuga que este módulo evita.',
    );
});
