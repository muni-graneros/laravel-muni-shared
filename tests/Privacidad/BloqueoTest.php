<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bloqueos;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.bloquear_durante_solicitud' => true]);
    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        // Adulta: el régimen de edad de Solicitudes exige la fecha acreditada.
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'atencion', 'nombre' => 'Atenciones',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);
    $this->verificacion = new ResultadoVerificacion(true, 'cedula_presencial');
});

it('un titular sin bloqueos no está bloqueado', function () {
    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});

it('bloquear una finalidad no bloquea las demás', function () {
    $otra = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);

    app(Bloqueos::class)->bloquear($this->titular, $this->finalidad, 'Rectificación en trámite');

    expect(app(Bloqueos::class)->vigente($this->titular, $this->finalidad))->toBeTrue()
        ->and(app(Bloqueos::class)->vigente($this->titular, $otra))->toBeFalse();
});

it('un bloqueo sin finalidad alcanza a todas', function () {
    app(Bloqueos::class)->bloquear($this->titular, null, 'Oposición general');

    expect(app(Bloqueos::class)->vigente($this->titular, $this->finalidad))->toBeTrue()
        ->and(app(Bloqueos::class)->vigente($this->titular))->toBeTrue();
});

it('registrar una rectificación bloquea automáticamente', function () {
    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal', $this->verificacion,
    );

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeTrue();
});

it('un acceso NO bloquea: no hay nada en disputa', function () {
    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Acceso, 'Quiero mis datos', $this->verificacion,
    );

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});

it('resolver la solicitud levanta su bloqueo', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal', $this->verificacion,
    );

    app(Solicitudes::class)->acoger($solicitud, 'Corregido con cédula a la vista.');

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse()
        // El bloqueo no se borra: queda con fecha de levantamiento.
        ->and(Bloqueo::count())->toBe(1)
        ->and(Bloqueo::sole()->levantado_en)->not->toBeNull();
});

it('rechazar la solicitud también levanta el bloqueo', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Oposicion, 'Me opongo', $this->verificacion,
    );

    app(Solicitudes::class)->rechazar($solicitud, 'No procede: el tratamiento se funda en la ley.');

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});

it('acoger una oposición NO levanta el bloqueo: el tratamiento cesa', function () {
    // Al revés del derecho era lo que hacía antes. El bloqueo de una oposición
    // es preventivo mientras se resuelve; acogerla significa darle la razón al
    // titular, o sea que el tratamiento tiene que CESAR. Levantarlo ahí es
    // justo lo contrario de lo que resolvió el municipio.
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Oposicion, 'Me opongo a que usen mis datos para difusión', $this->verificacion,
    );

    app(Solicitudes::class)->acoger($solicitud, 'Se acoge: la finalidad se funda en el consentimiento y lo retira.');

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeTrue()
        ->and(Bloqueo::sole()->levantado_en)->toBeNull()
        // El motivo deja de decir «en trámite», que ya no es cierto.
        ->and(Bloqueo::sole()->motivo)->toContain('cesa');
});

it('acoger parcialmente una oposición tampoco levanta el bloqueo', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Oposicion, 'Me opongo', $this->verificacion,
    );

    app(Solicitudes::class)->acogerParcialmente($solicitud, 'Cesa la difusión; el registro comunal se conserva.');

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeTrue();
});

it('acoger una oposición hace cesar el tratamiento aunque no se hubiera bloqueado al registrarla', function () {
    // Con `bloquear_durante_solicitud` apagado no hay bloqueo preventivo que
    // volver definitivo. Sin esto, acoger la oposición no tendría NINGÚN
    // efecto sobre el tratamiento: la solicitud quedaría «acogida» y el
    // sistema seguiría tratando el dato exactamente igual que antes.
    config(['privacidad.bloquear_durante_solicitud' => false]);

    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Oposicion, 'Me opongo', $this->verificacion,
    );

    expect(Bloqueo::count())->toBe(0);

    app(Solicitudes::class)->acoger($solicitud, 'Se acoge la oposición.');

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeTrue()
        ->and(Bloqueo::sole()->solicitud_id)->toBe($solicitud->getKey())
        // Sin finalidad: alcanza a todas, que es lo que corresponde a una
        // oposición que el municipio acogió sin acotarla.
        ->and(Bloqueo::sole()->finalidad_id)->toBeNull();
});

it('el cese de una oposición acogida queda en la bitácora', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Oposicion, 'Me opongo', $this->verificacion,
    );

    app(Solicitudes::class)->acoger($solicitud, 'Se acoge.');

    $constancia = EntradaBitacora::where('evento', 'bloqueo.definitivo')->sole();

    expect($constancia->datos['solicitud_id'])->toBe($solicitud->getKey())
        ->and($constancia->datos['bloqueos'])->toBe(1)
        ->and(EntradaBitacora::where('evento', 'bloqueo.levantado')->count())->toBe(0);
});

it('con la configuración apagada no bloquea nada', function () {
    config(['privacidad.bloquear_durante_solicitud' => false]);

    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal', $this->verificacion,
    );

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});
