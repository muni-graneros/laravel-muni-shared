<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bloqueos;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\ResolucionInvalida;
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

it('un bloqueo puesto en otro sistema no cesa el tratamiento en este', function () {
    // `privacidad_bloqueos` la comparten los ocho sistemas del ecosistema, y
    // `vigente()` no miraba la columna `sistema` que `bloquear()` sí escribe.
    // El efecto era una oposición acogida en licencias dejando sin atención a
    // alguien en discapacidad, que nunca lo pidió.
    config(['privacidad.sistema' => 'licencias']);

    app(Bloqueos::class)->bloquear($this->titular, null, 'Oposición acogida en licencias');

    config(['privacidad.sistema' => 'discapacidad']);

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse()
        ->and(app(Bloqueos::class)->vigente($this->titular, $this->finalidad))->toBeFalse();
});

it('el bloqueo de otro sistema se puede ver, para que nadie cese de menos en silencio', function () {
    config(['privacidad.sistema' => 'licencias']);
    app(Bloqueos::class)->bloquear($this->titular, null, 'Oposición acogida en licencias');

    config(['privacidad.sistema' => 'discapacidad']);
    app(Bloqueos::class)->bloquear($this->titular, $this->finalidad, 'Oposición acogida acá');

    expect(app(Bloqueos::class)->sistemasConBloqueoVigente($this->titular))
        ->toBe(['discapacidad', 'licencias']);
});

it('un bloqueo levantado ya no aparece entre los sistemas del ecosistema', function () {
    config(['privacidad.sistema' => 'licencias']);
    $bloqueo = app(Bloqueos::class)->bloquear($this->titular, null, 'Rectificación en trámite');
    $bloqueo->update(['levantado_en' => now()]);

    config(['privacidad.sistema' => 'discapacidad']);

    expect(app(Bloqueos::class)->sistemasConBloqueoVigente($this->titular))->toBe([]);
});

it('un bloqueo sin solicitud se puede levantar', function () {
    // Solo existía `levantarPorSolicitud()`: un bloqueo puesto a mano —o el que
    // crea `volverDefinitivos()` cuando la bandera está apagada— no tenía
    // ninguna vía del módulo para levantarse. En producción eso es un callejón
    // sin salida, y el sistema adoptante termina haciendo UPDATE a mano sobre
    // una tabla del módulo.
    $bloqueo = app(Bloqueos::class)->bloquear($this->titular, null, 'Se opuso por teléfono');

    $levantado = app(Bloqueos::class)->levantar($bloqueo, 'Retiró la oposición por escrito el 12-08.');

    expect($levantado)->toBeTrue()
        ->and(app(Bloqueos::class)->vigente($this->titular))->toBeFalse()
        ->and($bloqueo->fresh()->levantado_en)->not->toBeNull()
        // El motivo original NO se pisa: es lo que explica por qué se suspendió.
        ->and($bloqueo->fresh()->motivo)->toBe('Se opuso por teléfono')
        ->and($bloqueo->fresh()->levantado_motivo)->toBe('Retiró la oposición por escrito el 12-08.');
});

it('levantar deja la misma constancia que levantar por solicitud', function () {
    $bloqueo = app(Bloqueos::class)->bloquear($this->titular, null, 'Se opuso por teléfono');

    app(Bloqueos::class)->levantar($bloqueo, 'Retiró la oposición.');

    $constancia = EntradaBitacora::where('evento', 'bloqueo.levantado')->sole();

    expect($constancia->datos['bloqueo_id'])->toBe($bloqueo->getKey())
        // Las dos claves que ya escribía `levantarPorSolicitud()`, para que
        // «acá se levantó un bloqueo» se busque siempre igual.
        ->and($constancia->datos['bloqueos'])->toBe(1)
        ->and($constancia->datos)->toHaveKey('solicitud_id')
        // El texto del funcionario NO viaja a la bitácora: su invariante es
        // nombres de campo e ids, nunca prosa que pueda nombrar a alguien.
        ->and($constancia->datos)->not->toHaveKey('motivo');
});

it('levantar un bloqueo exige decir por qué', function () {
    // Levantar un cese es reanudar el tratamiento de alguien que había obtenido
    // que se detuviera. El módulo exige fundamento para toda resolución de una
    // solicitud; esto es la misma decisión y no puede quedar sin explicación.
    $bloqueo = app(Bloqueos::class)->bloquear($this->titular, null, 'Oposición acogida');

    expect(fn () => app(Bloqueos::class)->levantar($bloqueo, '   '))
        ->toThrow(ResolucionInvalida::class);

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeTrue();
});

it('un sistema no puede levantar el bloqueo de otro', function () {
    config(['privacidad.sistema' => 'licencias']);
    $bloqueo = app(Bloqueos::class)->bloquear($this->titular, null, 'Oposición acogida en licencias');

    config(['privacidad.sistema' => 'discapacidad']);

    expect(fn () => app(Bloqueos::class)->levantar($bloqueo, 'Acá no molesta.'))
        ->toThrow(ResolucionInvalida::class);

    expect($bloqueo->fresh()->levantado_en)->toBeNull();
});

it('levantar dos veces no reescribe la fecha ni duplica la constancia', function () {
    $bloqueo = app(Bloqueos::class)->bloquear($this->titular, null, 'Oposición acogida');

    app(Bloqueos::class)->levantar($bloqueo, 'Retiró la oposición.');
    $primera = $bloqueo->fresh()->levantado_en;

    expect(app(Bloqueos::class)->levantar($bloqueo->fresh(), 'De nuevo.'))->toBeFalse()
        ->and($bloqueo->fresh()->levantado_en->eq($primera))->toBeTrue()
        ->and($bloqueo->fresh()->levantado_motivo)->toBe('Retiró la oposición.')
        ->and(EntradaBitacora::where('evento', 'bloqueo.levantado')->count())->toBe(1);
});

it('con la configuración apagada no bloquea nada', function () {
    config(['privacidad.bloquear_durante_solicitud' => false]);

    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal', $this->verificacion,
    );

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});
