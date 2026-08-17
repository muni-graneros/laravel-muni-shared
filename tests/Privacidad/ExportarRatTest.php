<?php

use Illuminate\Support\Facades\Artisan;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(function () {
    // Sin TTY (este entorno de test, CI) Symfony cae a 80 columnas y envuelve
    // las celdas de la tabla, partiendo textos como "Ley 20.422" en dos
    // líneas. Es una circunstancia del arnés de test, no del comando: se fija
    // acá y no en el comando, para no forzar un ancho de tabla arbitrario
    // sobre la sesión real de un funcionario municipal.
    $this->anchoOriginal = getenv('COLUMNS');
    putenv('COLUMNS=200');

    config([
        'privacidad.sistema' => 'discapacidad',
        'privacidad.responsable.nombre' => 'I. Municipalidad de Graneros',
    ]);

    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal de personas con discapacidad',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 120,
    ]);
});

afterEach(function () {
    putenv($this->anchoOriginal === false ? 'COLUMNS' : "COLUMNS={$this->anchoOriginal}");
});

it('imprime el RAT del sistema con su base de licitud y norma', function () {
    // Se usa Artisan::call()+output() y no la cadena
    // $this->artisan(...)->expectsOutputToContain(...): el mock de salida de
    // testing empareja cada línea escrita con UNA sola expectativa, y como
    // «registro_comunal» y «Ley 20.422» caen en la misma fila de la tabla,
    // la segunda expectativa nunca se marca como cumplida y el test falla
    // pese a que la tabla sí muestra ambos datos.
    $codigo = Artisan::call('privacidad:rat');

    expect($codigo)->toBe(0);

    $salida = Artisan::output();

    expect($salida)
        ->toContain('registro_comunal')
        ->toContain('Ley 20.422');
});

it('exporta el RAT en json con el responsable del tratamiento', function () {
    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $salida = Artisan::output();
    $rat = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);

    expect($rat['responsable']['nombre'])->toBe('I. Municipalidad de Graneros')
        ->and($rat['finalidades'][0]['codigo'])->toBe('registro_comunal')
        ->and($rat['finalidades'][0]['base_licitud'])->toBe('funcion_legal');
});

it('avisa cuando el sistema no declaró ninguna finalidad', function () {
    Finalidad::query()->delete();

    $codigo = Artisan::call('privacidad:rat');

    expect($codigo)->toBe(0)
        ->and(Artisan::output())->toContain('no declaró');
});

it('exporta json válido con finalidades vacío cuando el sistema no declaró nada', function () {
    // Quien usa --json lo hace para pasarle la salida a json_decode: una
    // advertencia en texto plano en vez de JSON rompe a cualquier consumidor,
    // justo cuando el sistema más necesita quedar documentado (sin
    // finalidades declaradas).
    Finalidad::query()->delete();

    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $salida = Artisan::output();
    $rat = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);

    expect($rat['sistema'])->toBe('discapacidad')
        ->and($rat['responsable']['nombre'])->toBe('I. Municipalidad de Graneros')
        ->and($rat['finalidades'])->toBe([]);
});

it('sin PRIVACIDAD_SISTEMA configurado no revienta: informa que no hay finalidades', function () {
    // El default de config es null, no un marcador plausible como "sistema":
    // ese valor llegaba a salida que se le muestra a la autoridad. Nulo, el
    // comando tiene que seguir corriendo (scopeDelSistema tipa string).
    config(['privacidad.sistema' => null]);

    $codigo = Artisan::call('privacidad:rat');

    expect($codigo)->toBe(0)
        ->and(Artisan::output())->toContain('no declaró');
});

it('en json, sin PRIVACIDAD_SISTEMA configurado, advierte y falla en vez de declarar que no hay nada', function () {
    // Hallazgo 8 de la verificación en vivo: con PRIVACIDAD_SISTEMA vacío el
    // comando emitía JSON válido, completo, con "sistema": "" y
    // "finalidades": [] y salía con código 0 — indistinguible de un sistema
    // configurado que en efecto no declara nada. Acá se prueban las dos
    // señales nuevas a la vez: el código de salida (para quien solo mira
    // `$?`) y la clave `advertencias` del propio documento (para quien solo
    // se queda con el JSON).
    config(['privacidad.sistema' => null]);

    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(1);

    // Sigue siendo JSON válido y completo pese al código de salida: quien
    // archivó únicamente la salida estándar no se queda sin nada, se queda
    // con un documento que se declara a sí mismo no confiable.
    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['sistema'])->toBe('')
        ->and($rat['finalidades'])->toBe([])
        ->and($rat['advertencias'])->toContain(
            'PRIVACIDAD_SISTEMA no está configurado: este RAT no identifica ningún sistema '
            .'y no puede tomarse como la declaración de que no se trata información personal.',
        );
});

it('en json, con el sistema configurado pero sin finalidades declaradas, advierte sin fallar', function () {
    // El mismo "finalidades": [] que el test anterior, pero por un motivo
    // legítimo: un sistema recién adoptado, antes de correr el seeder. Acá
    // SÍ se identificó el sistema, así que el documento no miente sobre de
    // quién habla — solo hay que decir que todavía no declaró nada. Por eso
    // el código de salida se mantiene en 0: es el mismo criterio que ya
    // regía para el camino de tabla.
    Finalidad::query()->delete();

    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['sistema'])->toBe('discapacidad')
        ->and($rat['advertencias'])->toContain('El sistema «discapacidad» no declaró ninguna finalidad de tratamiento.');
});

it('en json, no exporta destinatarios: la fuente de a quién se comunican los datos es encargados', function () {
    // Hallazgo 9: `destinatarios` es la columna legacy que `encargados` (con
    // contrato y vigencia) reemplazó, y en el sistema verificado venía
    // `null` en las seis finalidades pese a que una de ellas sí tenía un
    // Encargado declarado. Exportar ambas cosas era confuso, no informativo.
    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['finalidades'][0])->not->toHaveKey('destinatarios')
        ->and($rat['finalidades'][0])->toHaveKey('encargados');
});

it('en json, advierte cuando el responsable del tratamiento está incompleto', function () {
    // Hallazgo 9: contacto y delegado quedan en blanco por defecto (el .env
    // real los trae presentes-pero-vacíos) y nadie lo decía. `nombre` sí está
    // configurado en el beforeEach, así que no debe aparecer entre lo que
    // falta.
    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $advertenciaResponsable = collect($rat['advertencias'])
        ->first(fn (string $a): bool => str_contains($a, 'responsable del tratamiento está incompleto'));

    expect($advertenciaResponsable)
        ->not->toBeNull()
        ->toContain('contacto para ejercer derechos')
        ->toContain('delegado de protección de datos')
        ->not->toContain('nombre del responsable');
});

it('en json, no advierte sobre el responsable cuando nombre, contacto y delegado están completos', function () {
    config([
        'privacidad.responsable.contacto' => 'privacidad@graneros.cl',
        'privacidad.responsable.delegado' => 'Delegado de Protección de Datos',
    ]);

    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['advertencias'])->toBe([]);
});

it('en la tabla también avisa cuando el responsable del tratamiento está incompleto', function () {
    $this->artisan('privacidad:rat')
        ->expectsOutputToContain('El responsable del tratamiento está incompleto')
        ->assertSuccessful();
});

it('exporta finalidades activas e inactivas por igual, distinguiendo el estado', function () {
    // El RAT no filtra por `activa`: una finalidad dada de baja sigue siendo
    // parte del historial de tratamiento y ocultarla sería una forma distinta
    // de tergiversar lo que el sistema hizo. Se muestra el estado, no se
    // recorta la lista.
    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'campana_censo_2019',
        'nombre' => 'Campaña de censo comunal 2019',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'activa' => false,
    ]);

    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $finalidades = collect($rat['finalidades'])->keyBy('codigo');

    expect($finalidades)->toHaveCount(2)
        ->and($finalidades['registro_comunal']['activa'])->toBeTrue()
        ->and($finalidades['campana_censo_2019']['activa'])->toBeFalse();
});
