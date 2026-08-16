<?php

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\EdadNoAcreditada;
use Muni\Shared\Privacidad\IdentidadNoVerificada;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\RepresentacionNoAcreditada;
use Muni\Shared\Privacidad\RepresentacionRequerida;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitante;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->verificacion = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']);

    $this->nna = PersonaDePrueba::create([
        'nombre' => 'Menor',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    $this->adulta = PersonaDePrueba::create([
        'nombre' => 'Adulta',
        'documento' => '22.222.222-2',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
});

it('un NNA no ejerce solo ninguno de los cinco derechos', function (TipoDeSolicitud $tipo) {
    // La asimetría ERA el defecto: el mismo niño de 10 años no podía consentir
    // que le publicaran una foto y sí podía pedir la copia íntegra de su
    // registro de discapacidad —datos de salud—, que le acogieran una supresión
    // que lo saca de un registro comunal del que su familia puede depender, o
    // una rectificación que se propaga al maestro federado de personas.
    expect(fn () => app(Solicitudes::class)->registrar(
        $this->nna, $tipo, 'Lo pido yo', $this->verificacion,
    ))->toThrow(RepresentacionRequerida::class);

    expect(Solicitud::query()->count())->toBe(0);
})->with([
    TipoDeSolicitud::Acceso,
    TipoDeSolicitud::Rectificacion,
    TipoDeSolicitud::Supresion,
    TipoDeSolicitud::Oposicion,
    TipoDeSolicitud::Portabilidad,
]);

it('el representante legal de un NNA sí ejerce, acreditando la representación', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->nna,
        TipoDeSolicitud::Acceso,
        'Vengo por mi hijo',
        $this->verificacion,
        Solicitante::RepresentanteLegal,
        'acreditaciones/certificado-nacimiento.pdf',
    );

    expect($solicitud->exists)->toBeTrue()
        ->and($solicitud->solicitante)->toBe(Solicitante::RepresentanteLegal)
        ->and($solicitud->acreditacion_path)->toBe('acreditaciones/certificado-nacimiento.pdf');
});

it('el representante legal de un NNA sin acreditar no ejerce', function () {
    expect(fn () => app(Solicitudes::class)->registrar(
        $this->nna,
        TipoDeSolicitud::Supresion,
        'Vengo por mi hijo',
        $this->verificacion,
        Solicitante::RepresentanteLegal,
    ))->toThrow(RepresentacionNoAcreditada::class);

    expect(Solicitud::query()->count())->toBe(0);
});

it('el apoderado de un NNA tampoco: un menor no puede otorgar mandato', function () {
    expect(fn () => app(Solicitudes::class)->registrar(
        $this->nna,
        TipoDeSolicitud::Acceso,
        'Me mandó él',
        $this->verificacion,
        Solicitante::Apoderado,
        'acreditaciones/mandato.pdf',
    ))->toThrow(RepresentacionRequerida::class);
});

it('con la edad sin acreditar no se registra ninguna solicitud', function () {
    // Mismo criterio que el consentimiento: null es «no se sabe», no «es
    // adulto». Entregar el expediente completo de quien puede ser un menor a
    // quien dice ser él mismo es la fuga que este módulo existe para impedir.
    $sinFecha = PersonaDePrueba::create(['nombre' => 'Sin fecha', 'documento' => '33.333.333-3']);

    expect(fn () => app(Solicitudes::class)->registrar(
        $sinFecha, TipoDeSolicitud::Acceso, 'Quiero mis datos', $this->verificacion,
    ))->toThrow(EdadNoAcreditada::class);

    // Ni siquiera con el representante legal acreditado: sin la edad no se sabe
    // si hace falta que firme un tercero.
    expect(fn () => app(Solicitudes::class)->registrar(
        $sinFecha,
        TipoDeSolicitud::Acceso,
        'Vengo por ella',
        $this->verificacion,
        Solicitante::RepresentanteLegal,
        'acreditaciones/certificado-nacimiento.pdf',
    ))->toThrow(EdadNoAcreditada::class);
});

it('un adulto que ejerce por apoderado también acredita el mandato', function () {
    expect(fn () => app(Solicitudes::class)->registrar(
        $this->adulta, TipoDeSolicitud::Acceso, 'Me mandó ella', $this->verificacion, Solicitante::Apoderado,
    ))->toThrow(RepresentacionNoAcreditada::class);

    $solicitud = app(Solicitudes::class)->registrar(
        $this->adulta,
        TipoDeSolicitud::Acceso,
        'Me mandó ella',
        $this->verificacion,
        Solicitante::Apoderado,
        'acreditaciones/mandato.pdf',
    );

    expect($solicitud->acreditacion_path)->toBe('acreditaciones/mandato.pdf');
});

it('el adulto que ejerce por sí mismo sigue sin fricción', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->adulta, TipoDeSolicitud::Acceso, 'Quiero mis datos', $this->verificacion,
    );

    expect($solicitud->exists)->toBeTrue()
        ->and($solicitud->acreditacion_path)->toBeNull();
});

it('un titular que no implementa el contrato no pasa por el régimen de edad', function () {
    // Misma frontera que en el consentimiento: a quien no firmó el contrato no
    // se le puede preguntar la fecha de nacimiento ni exigir que la tenga.
    $ajeno = new class extends Model
    {
        protected $table = 'personas_de_prueba';

        protected $guarded = [];
    };

    $ajeno->forceFill(['nombre' => 'Modelo ajeno'])->save();

    expect(app(Solicitudes::class)->registrar(
        $ajeno, TipoDeSolicitud::Acceso, 'Quiero mis datos', $this->verificacion,
    )->exists)->toBeTrue();
});

it('la identidad no verificada se rechaza antes que la edad', function () {
    // El orden importa para el operador: si las dos cosas faltan, lo primero
    // que hay que resolver es quién es quien está al otro lado del mesón.
    $sinFecha = PersonaDePrueba::create(['nombre' => 'Sin fecha', 'documento' => '33.333.333-3']);

    expect(fn () => app(Solicitudes::class)->registrar(
        $sinFecha,
        TipoDeSolicitud::Acceso,
        'Quiero mis datos',
        new ResultadoVerificacion(false, 'ninguno', ['motivo' => 'no presentó cédula']),
    ))->toThrow(IdentidadNoVerificada::class);
});
