<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Bloqueos;
use Muni\Shared\Privacidad\CifradoCast;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

/**
 * El texto libre del módulo va cifrado en reposo.
 *
 * `detalle` es prosa dictada por el ciudadano («mi RUT es…, vivo en…»),
 * `verificacion_identidad.evidencia` guarda el RUN con que se acreditó, y los
 * motivos de un bloqueo los dicta un funcionario nombrando a la persona o a un
 * familiar. En discapacidad además la solicitud puede describir un
 * diagnóstico. Todo eso estaba en claro en dos tablas que comparten los ocho
 * sistemas, cuando la regla transversal del ecosistema es que un dato personal
 * sensible no se guarda en texto plano.
 *
 * Lo que se afirma acá se afirma mirando la FILA por query builder, no el
 * modelo: el modelo devuelve el texto en claro por diseño, así que preguntarle
 * a él no prueba nada sobre lo que quedó en disco.
 */
const RUT_CIFRADO = '11.111.111-1';

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.bloquear_durante_solicitud' => true]);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => RUT_CIFRADO,
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
    $this->verificacion = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => RUT_CIFRADO]);

    $this->filaSolicitud = fn (Solicitud $s) => DB::table('privacidad_solicitudes')->where('id', $s->getKey())->sole();
    $this->filaBloqueo = fn (Bloqueo $b) => DB::table('privacidad_bloqueos')->where('id', $b->getKey())->sole();
});

it('el detalle y la evidencia de identidad quedan cifrados en la fila y en claro en el modelo', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Mi RUT es '.RUT_CIFRADO.' y vivo en Los Aromos 123: quiero saber qué tienen de mí',
        $this->verificacion,
    );

    $fila = ($this->filaSolicitud)($solicitud);

    expect((string) $fila->detalle)->not->toContain('11.111.111')
        ->and((string) $fila->detalle)->not->toContain('Los Aromos')
        ->and((string) $fila->verificacion_identidad)->not->toContain('11.111.111')
        // Y el modelo sigue entregando lo mismo de siempre.
        ->and($solicitud->fresh()->detalle)->toContain('Los Aromos 123')
        ->and($solicitud->fresh()->verificacion_identidad)->toBe([
            'metodo' => 'cedula_presencial',
            'evidencia' => ['run' => RUT_CIFRADO],
        ]);
});

it('el fundamento de la resolución queda cifrado', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Acceso, 'Quiero mi ficha', $this->verificacion,
    );

    app(Solicitudes::class)->acoger($solicitud, 'Se entregó el expediente a Rocío Paredes en el mesón.');

    $fila = ($this->filaSolicitud)($solicitud);

    expect((string) $fila->fundamento_resolucion)->not->toContain('Rocío')
        ->and($solicitud->fresh()->fundamento_resolucion)->toContain('Rocío Paredes');
});

it('el motivo de un bloqueo y el de su levantamiento quedan cifrados', function () {
    $bloqueo = app(Bloqueos::class)->bloquear($this->titular, null, 'La hija de Rocío Paredes llamó a reclamar');

    expect((string) ($this->filaBloqueo)($bloqueo)->motivo)->not->toContain('Rocío')
        ->and($bloqueo->fresh()->motivo)->toContain('Rocío Paredes');

    // levantar() escribe por update() masivo, que NO pasa por los casts del
    // modelo: es el camino que más fácil se queda en claro.
    app(Bloqueos::class)->levantar($bloqueo, 'Rocío Paredes retiró el reclamo por escrito');

    $fila = ($this->filaBloqueo)($bloqueo);

    expect((string) $fila->levantado_motivo)->not->toContain('Rocío')
        ->and($bloqueo->fresh()->levantado_motivo)->toBe('Rocío Paredes retiró el reclamo por escrito');
});

it('el motivo que reescribe una oposición acogida también queda cifrado', function () {
    // volverDefinitivos() es el otro update() masivo sobre `motivo`.
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Oposicion, 'Me opongo', $this->verificacion,
    );

    app(Solicitudes::class)->acoger($solicitud, 'Se acoge.');

    $bloqueo = Bloqueo::sole();

    expect((string) ($this->filaBloqueo)($bloqueo)->motivo)->not->toContain('cesa')
        ->and($bloqueo->motivo)->toContain('cesa');
});

it('el centinela de la anonimización queda cifrado y el modelo lo sigue leyendo como tal', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi RUT es '.RUT_CIFRADO, $this->verificacion,
    );
    $bloqueo = Bloqueo::sole();

    app(Bitacora::class)->desvincular($this->titular);

    expect((string) ($this->filaSolicitud)($solicitud)->detalle)->not->toBe(Bitacora::SUPRIMIDO)
        ->and($solicitud->fresh()->detalle)->toBe(Bitacora::SUPRIMIDO)
        ->and((string) ($this->filaBloqueo)($bloqueo)->motivo)->not->toBe(Bitacora::SUPRIMIDO)
        ->and($bloqueo->fresh()->motivo)->toBe(Bitacora::SUPRIMIDO);
});

it('la evidencia de identidad purgada al anonimizar se reescribe cifrada, conservando el método', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Acceso, 'Quiero mi ficha', $this->verificacion,
    );

    app(Bitacora::class)->desvincular($this->titular);

    $fila = ($this->filaSolicitud)($solicitud);

    expect((string) $fila->verificacion_identidad)->not->toContain('cedula_presencial')
        ->and((string) $fila->verificacion_identidad)->not->toContain('11.111.111')
        ->and($solicitud->fresh()->verificacion_identidad)->toBe(['metodo' => 'cedula_presencial', 'evidencia' => []]);
});

// ── Compatibilidad con lo que ya está escrito en producción ──

it('una fila anterior al cifrado se sigue leyendo en claro', function () {
    // Los ocho sistemas tienen filas escritas antes de esta versión. Entre el
    // despliegue y la corrida del comando que las cifra, el panel tiene que
    // seguir abriendo.
    $id = DB::table('privacidad_solicitudes')->insertGetId([
        'sistema' => 'discapacidad',
        'titular_type' => $this->titular->getMorphClass(),
        'titular_id' => $this->titular->getKey(),
        'tipo' => 'acceso',
        'estado' => 'recibida',
        'recibida_en' => now(),
        'vence_en' => now()->addDays(30),
        'detalle' => 'Escrito antes del cifrado',
        'verificacion_identidad' => json_encode(['metodo' => 'cedula_presencial', 'evidencia' => ['run' => RUT_CIFRADO]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $solicitud = Solicitud::findOrFail($id);

    expect($solicitud->detalle)->toBe('Escrito antes del cifrado')
        ->and($solicitud->verificacion_identidad['evidencia'])->toBe(['run' => RUT_CIFRADO]);
});

it('un cifrado con otra clave NO se lee como texto en claro: truena', function () {
    // La tolerancia con las filas viejas no puede convertirse en fallo abierto:
    // un payload que SÍ tiene forma de cifrado y no valida su MAC es una
    // APP_KEY cambiada o una fila manipulada, y eso se tiene que ver.
    $otraClave = new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC');

    $id = DB::table('privacidad_solicitudes')->insertGetId([
        'sistema' => 'discapacidad',
        'titular_type' => $this->titular->getMorphClass(),
        'titular_id' => $this->titular->getKey(),
        'tipo' => 'acceso',
        'estado' => 'recibida',
        'recibida_en' => now(),
        'vence_en' => now()->addDays(30),
        'detalle' => $otraClave->encryptString('cifrado con la clave de otro sistema'),
        'verificacion_identidad' => json_encode(['metodo' => 'cedula_presencial', 'evidencia' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => Solicitud::findOrFail($id)->detalle)->toThrow(DecryptException::class);
});

it('el comando cifra lo que quedó en claro, no toca lo ya cifrado y es idempotente', function () {
    // Una fila nueva, ya cifrada por el módulo…
    $nueva = app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Acceso, 'Nueva, ya cifrada', $this->verificacion,
    );
    $bloqueoNuevo = app(Bloqueos::class)->bloquear($this->titular, null, 'Nuevo, ya cifrado');

    // …y dos filas viejas, escritas en claro por la versión anterior.
    $viejaId = DB::table('privacidad_solicitudes')->insertGetId([
        'sistema' => 'discapacidad',
        'titular_type' => $this->titular->getMorphClass(),
        'titular_id' => $this->titular->getKey(),
        'tipo' => 'acceso',
        'estado' => 'acogida',
        'recibida_en' => now(),
        'vence_en' => now()->addDays(30),
        'detalle' => 'Mi RUT es '.RUT_CIFRADO,
        'fundamento_resolucion' => 'Se entregó a Rocío Paredes',
        'verificacion_identidad' => json_encode(['metodo' => 'cedula_presencial', 'evidencia' => ['run' => RUT_CIFRADO]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $bloqueoViejoId = DB::table('privacidad_bloqueos')->insertGetId([
        'sistema' => 'discapacidad',
        'titular_type' => $this->titular->getMorphClass(),
        'titular_id' => $this->titular->getKey(),
        'motivo' => 'La hija de Rocío Paredes llamó',
        'desde' => now(),
        'levantado_en' => now(),
        'levantado_motivo' => 'Rocío Paredes retiró el reclamo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cifradaAntes = ($this->filaSolicitud)($nueva)->detalle;

    // Sin --ejecutar solo informa: nada cambia.
    Artisan::call('privacidad:cifrar-texto-libre');

    expect(Artisan::output())->toContain('privacidad_solicitudes')->toContain('1')
        ->and((string) DB::table('privacidad_solicitudes')->where('id', $viejaId)->value('detalle'))->toContain('11.111.111');

    Artisan::call('privacidad:cifrar-texto-libre', ['--ejecutar' => true]);

    $vieja = DB::table('privacidad_solicitudes')->where('id', $viejaId)->sole();
    $bloqueoViejo = DB::table('privacidad_bloqueos')->where('id', $bloqueoViejoId)->sole();

    expect((string) $vieja->detalle)->not->toContain('11.111.111')
        ->and((string) $vieja->fundamento_resolucion)->not->toContain('Rocío')
        ->and((string) $vieja->verificacion_identidad)->not->toContain('11.111.111')
        ->and((string) $bloqueoViejo->motivo)->not->toContain('Rocío')
        ->and((string) $bloqueoViejo->levantado_motivo)->not->toContain('Rocío')
        // Lo que se lee después es lo mismo que había.
        ->and(Solicitud::findOrFail($viejaId)->detalle)->toBe('Mi RUT es '.RUT_CIFRADO)
        ->and(Solicitud::findOrFail($viejaId)->verificacion_identidad['evidencia'])->toBe(['run' => RUT_CIFRADO])
        ->and(Bloqueo::findOrFail($bloqueoViejoId)->levantado_motivo)->toBe('Rocío Paredes retiró el reclamo')
        // Lo ya cifrado no se vuelve a cifrar: el mismo ciphertext, byte a byte.
        ->and(($this->filaSolicitud)($nueva)->detalle)->toBe($cifradaAntes)
        ->and($bloqueoNuevo->fresh()->motivo)->toBe('Nuevo, ya cifrado');

    // Segunda corrida: no queda nada que cifrar.
    Artisan::call('privacidad:cifrar-texto-libre', ['--ejecutar' => true]);

    expect(Artisan::output())->toContain('0')
        ->and(DB::table('privacidad_solicitudes')->where('id', $viejaId)->value('detalle'))->toBe($vieja->detalle);
});

// ── Guardias de deriva ──

it('toda columna que el barrido suprime con centinela o vacía por dentro está cifrada en su modelo', function () {
    // Si el barrido de Bitacora tiene que poner «[suprimido al anonimizar]» en
    // una columna, es porque ahí va prosa que puede nombrar a la persona: esa
    // columna tiene que ir cifrada. Una columna nueva que entre a TABLAS con
    // centinela y sin cast aparece acá.
    /** @var array<string, array<string, mixed>> $tablas */
    $tablas = (new ReflectionClass(Bitacora::class))->getConstant('TABLAS');
    /** @var array<string, class-string<Model>> $modelos */
    $modelos = (new ReflectionClass(Bitacora::class))->getConstant('MODELOS');

    expect(array_keys($modelos))->toBe(array_keys($tablas));

    $sinCifrar = [];

    foreach ($tablas as $tabla => $columnas) {
        $cifradas = CifradoCast::columnasCifradasDe(new $modelos[$tabla]);

        foreach ($columnas as $columna => $valor) {
            $esProsa = $valor === Bitacora::SUPRIMIDO || str_contains($columna, '->');

            if ($esProsa && ! in_array(Str::before($columna, '->'), $cifradas, true)) {
                $sinCifrar[] = "{$tabla}.{$columna}";
            }
        }
    }

    expect($sinCifrar)->toBe([])
        ->and(CifradoCast::columnasCifradasDe(new Solicitud))->toBe(['detalle', 'fundamento_resolucion', 'verificacion_identidad'])
        ->and(CifradoCast::columnasCifradasDe(new Bloqueo))->toBe(['motivo', 'levantado_motivo']);
});
