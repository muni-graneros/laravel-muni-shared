<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Muni\Shared\Privacidad\ArchivoNoSuprimido;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\DiscoEvidenciaNoConfigurado;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config([
        'privacidad.sistema' => 'discapacidad',
        // Ya no hay default de fábrica (ver config/privacidad.php): cada test
        // que ejercite un documento real declara el disco, como cualquier
        // sistema adoptante. Los tests que ejercitan la falta de esta
        // configuración la sobrescriben a propósito.
        'privacidad.disco_evidencia' => 'local',
    ]);
    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        // Adultos: desde el régimen de NNA, otorgar() exige la edad acreditada.
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
    $this->otro = PersonaDePrueba::create([
        'nombre' => 'Otro Titular',
        'documento' => '22.222.222-2',
        'fecha_nacimiento' => now()->subYears(35)->toDateString(),
    ]);

    // La historia ocurre ANTES, como en la vida real. Una entrada escrita en el
    // mismo segundo que la desvinculación cae dentro de la ventana de
    // anonimización y queda huérfana sin referencia de grupo, a propósito: su
    // hora es la hora en que se anonimizó a la persona.
    $this->travelTo(now()->subDays(3));

    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['a' => 1], $this->titular);
    app(RegistroDeEvidencia::class)->registrar('solicitud.acogida', ['b' => 2], $this->titular);
    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['c' => 3], $this->otro);

    $this->travelBack();
});

it('corta el vínculo con el titular pero conserva las entradas', function () {
    $barrido = app(Bitacora::class)->desvincular($this->titular);

    expect($barrido->filas)->toBe(2)
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

it('lo escrito dentro de la propia anonimización queda huérfano pero sin referencia', function () {
    // Es la entrada que AplicarRetencion escribe justo antes de desvincular.
    // Lleva la hora exacta de la anonimización —la misma que queda congelada en
    // el updated_at de la persona—, así que darle la referencia de grupo
    // encadenaría ese instante con todas las filas huérfanas del caso.
    app(RegistroDeEvidencia::class)->registrar('retencion.aplicada', ['finalidad' => 'atencion'], $this->titular);

    app(Bitacora::class)->desvincular($this->titular);

    $deLaAnonimizacion = EntradaBitacora::where('evento', 'retencion.aplicada')->sole();

    expect($deLaAnonimizacion->titular_id)->toBeNull()
        ->and($deLaAnonimizacion->titular_ref)->toBeNull()
        // Y las de la historia previa sí se agrupan: la ventana separa, no borra.
        ->and(EntradaBitacora::whereNotNull('titular_ref')->count())->toBe(2);
});

it('la constancia por titular no publica la referencia ni ninguna cantidad', function () {
    // Se escribe en el instante de la anonimización, así que todo lo que lleve
    // adentro queda pegado a ese instante. El ref sería la llave directa. Las
    // cantidades por persona son la versión débil del mismo problema: «4 filas y
    // 1 documento» acota esa persona a un conjunto de filas huérfanas, y con el
    // orden de las constancias se termina de resolver. Van agregadas por corrida
    // (ver RetencionTest); acá queda el hecho, que es idéntico para todos.
    $barrido = app(Bitacora::class)->desvincular($this->titular);

    $constancia = EntradaBitacora::where('evento', 'bitacora.desvinculada')->sole();

    expect($constancia->datos)->toBe([])
        ->and($constancia->titular_ref)->toBeNull()
        ->and($constancia->titular_id)->toBeNull()
        // Y las cantidades siguen existiendo, pero como retorno: quien llama
        // decide qué publicar.
        ->and($barrido->filas)->toBe(2);
});

it('deja constancia de la propia desvinculación', function () {
    app(Bitacora::class)->desvincular($this->titular);

    expect(EntradaBitacora::where('evento', 'bitacora.desvinculada')->count())->toBe(1);
});

it('borra del disco los documentos referenciados, no solo la ruta', function () {
    // Anular la ruta sin borrar el archivo deja el consentimiento firmado en
    // disco —sigue siendo dato personal— y ya nadie sabe de quién era para
    // suprimirlo. Es el fallo que este test existe para impedir.
    Storage::fake('local');
    Storage::disk('local')->put('consentimientos/11111111-1.pdf', 'firma escaneada');

    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
    app(Consentimientos::class)->otorgar(
        $this->titular, $finalidad, MedioDeConsentimiento::FirmaPapel,
        ['evidencia_path' => 'consentimientos/11111111-1.pdf'],
    );

    $barrido = app(Bitacora::class)->desvincular($this->titular);

    Storage::disk('local')->assertMissing('consentimientos/11111111-1.pdf');

    expect($barrido->archivosSuprimidos)->toBe(1)
        ->and($barrido->archivosNoEncontrados)->toBe(0)
        ->and(Consentimiento::sole()->evidencia_path)->toBeNull();
});

it('un documento que no se pudo borrar aborta la anonimización en vez de darla por hecha', function () {
    // El defecto que esto cierra, verificado con un directorio de solo lectura:
    // Storage::delete() devolvía false, nadie miraba el retorno, la constancia
    // decía «archivos_suprimidos: 1», el PDF seguía en disco y la columna con su
    // ruta ya estaba anulada. Exactamente el estado que la feature existe para
    // impedir —un documento con datos personales, vivo y sin dueño— con el
    // registro afirmando lo contrario.
    //
    // Se aborta entero y no se sigue con la ruta intacta: conservar la ruta deja
    // el RUT escrito en el nombre del archivo dentro de una fila que el resto
    // del barrido ya dejó huérfana, o sea una anonimización a medias que además
    // contradice la garantía de que ninguna columna sobreviviente identifica al
    // titular. Con el throw la transacción vuelve atrás completa: la persona NO
    // queda marcada como anonimizada, la fila conserva su titular, el documento
    // conserva su ruta y la corrida se puede repetir cuando se arreglen los
    // permisos.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root ignora los permisos del directorio: el borrado no falla.');
    }

    $raiz = sys_get_temp_dir().'/privacidad-solo-lectura-'.Str::random(8);
    mkdir($raiz.'/consentimientos', 0755, true);
    file_put_contents($ruta = $raiz.'/consentimientos/11111111-1.pdf', 'firma escaneada');
    chmod($raiz.'/consentimientos', 0555);

    config([
        'filesystems.disks.evidencia_de_prueba' => ['driver' => 'local', 'root' => $raiz, 'throw' => false],
        'privacidad.disco_evidencia' => 'evidencia_de_prueba',
    ]);

    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
    app(Consentimientos::class)->otorgar(
        $this->titular, $finalidad, MedioDeConsentimiento::FirmaPapel,
        ['evidencia_path' => 'consentimientos/11111111-1.pdf'],
    );

    try {
        expect(fn () => app(Bitacora::class)->desvincular($this->titular))
            ->toThrow(ArchivoNoSuprimido::class);

        $consentimiento = Consentimiento::sole();

        expect(file_exists($ruta))->toBeTrue()
            // La ruta sigue apuntando al documento que sigue existiendo: es lo
            // que permite reintentar y encontrarlo.
            ->and($consentimiento->evidencia_path)->toBe('consentimientos/11111111-1.pdf')
            // Y nada del barrido quedó a medio aplicar.
            ->and($consentimiento->titular_id)->toEqual($this->titular->getKey())
            ->and(EntradaBitacora::where('evento', 'bitacora.desvinculada')->count())->toBe(0)
            ->and(EntradaBitacora::whereNotNull('titular_ref')->count())->toBe(0);
    } finally {
        chmod($raiz.'/consentimientos', 0755);
        @unlink($ruta);
        @rmdir($raiz.'/consentimientos');
        @rmdir($raiz);
    }
});

it('cuenta como no encontrado el archivo que ya no está, sin fallar', function () {
    // Un archivo ausente no es error: pudo borrarlo la purga del sistema
    // adoptante, que corre antes. Pero se cuenta, porque el mismo síntoma
    // aparece cuando `privacidad.disco_evidencia` apunta al disco equivocado y
    // se está anonimizando dejando expedientes vivos en otro lado.
    Storage::fake('local');

    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
    app(Consentimientos::class)->otorgar(
        $this->titular, $finalidad, MedioDeConsentimiento::FirmaPapel,
        ['evidencia_path' => 'consentimientos/inexistente.pdf'],
    );

    $barrido = app(Bitacora::class)->desvincular($this->titular);

    expect($barrido->archivosNoEncontrados)->toBe(1)
        ->and($barrido->archivosSuprimidos)->toBe(0);
});

it('sin disco_evidencia configurado, un titular sin archivos que borrar igual se desvincula', function () {
    // Comportamiento perezoso, a propósito: si esta persona nunca otorgó un
    // consentimiento con evidencia ni tiene una solicitud con respuesta o
    // acreditación, no hay ningún documento que buscar, así que no hace falta
    // resolver el disco para nada. Que el módulo trone igual sería negarse a
    // desvincular a alguien por una clave que, para su caso, es irrelevante.
    config(['privacidad.disco_evidencia' => '']);

    $barrido = app(Bitacora::class)->desvincular($this->titular);

    expect($barrido->filas)->toBeGreaterThan(0);
});

it('disco_evidencia en blanco truena apenas hay un documento que borrar, no lo da por resuelto', function () {
    // El defecto que esto cierra: `PRIVACIDAD_DISCO_EVIDENCIA=` (presente y
    // vacía) no activa el default de env() —solo lo hace la clave AUSENTE—, y
    // `Storage::disk('')` resuelve en silencio al disco por defecto de
    // Laravel. El barrido buscaba ahí, no encontraba nada, lo contaba como
    // `archivos_no_encontrados` y seguía: la anonimización terminaba
    // «lista» con el consentimiento firmado todavía en disco. Ahora truena
    // antes de tocar ningún disco.
    config(['privacidad.disco_evidencia' => '']);

    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
    app(Consentimientos::class)->otorgar(
        $this->titular, $finalidad, MedioDeConsentimiento::FirmaPapel,
        ['evidencia_path' => 'consentimientos/11111111-1.pdf'],
    );

    expect(fn () => app(Bitacora::class)->desvincular($this->titular))
        ->toThrow(DiscoEvidenciaNoConfigurado::class);

    // Y la transacción se revierte entera, igual que con ArchivoNoSuprimido:
    // ni la fila queda huérfana ni la constancia dice que se desvinculó.
    expect(Consentimiento::sole()->titular_id)->toEqual($this->titular->getKey())
        ->and(EntradaBitacora::where('evento', 'bitacora.desvinculada')->count())->toBe(0);
});

it('disco_evidencia ausente del todo se comporta igual que en blanco, no cae a ningún default', function () {
    // No es lo mismo, para env(), pero para este módulo tiene que serlo:
    // ninguna de las dos formas de «no configurado» puede dejar vivo un
    // documento con datos personales sin que nadie se entere.
    config(['privacidad.disco_evidencia' => null]);

    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
    app(Consentimientos::class)->otorgar(
        $this->titular, $finalidad, MedioDeConsentimiento::FirmaPapel,
        ['evidencia_path' => 'consentimientos/11111111-1.pdf'],
    );

    expect(fn () => app(Bitacora::class)->desvincular($this->titular))
        ->toThrow(DiscoEvidenciaNoConfigurado::class);
});

it('disco_evidencia con un nombre que no tiene driver configurado truena con el mismo tipo de excepción', function () {
    // Ya fallaba fuerte antes de este cambio —Storage::disk() lanza
    // InvalidArgumentException—, pero con la excepción propia de Laravel
    // escapando de un módulo que en el resto de sus fallos de configuración
    // usa sus propias clases. Se envuelve para que quien atrapa
    // DiscoEvidenciaNoConfigurado cubra las dos formas de mala configuración.
    config(['privacidad.disco_evidencia' => 'disco_que_no_existe']);

    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
    app(Consentimientos::class)->otorgar(
        $this->titular, $finalidad, MedioDeConsentimiento::FirmaPapel,
        ['evidencia_path' => 'consentimientos/11111111-1.pdf'],
    );

    expect(fn () => app(Bitacora::class)->desvincular($this->titular))
        ->toThrow(DiscoEvidenciaNoConfigurado::class);
});

it('la evidencia de identidad se vacía y el método sobrevive, en cualquier motor', function () {
    // Regresión del defecto que dejó la retención caída en producción con la
    // suite en verde: `'verificacion_identidad->evidencia' => []` dentro de un
    // update() compila `json_set(…, cast(? as json))`, y MariaDB no soporta
    // `CAST(x AS JSON)`. Este test vale por lo que afirma —qué queda escrito—
    // pero sobre todo por dónde se corre: contra MariaDB fallaba con un 1064.
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Quiero mi ficha',
        new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']),
    );

    app(Bitacora::class)->desvincular($this->titular);

    $fila = DB::table('privacidad_solicitudes')->where('id', $solicitud->getKey())->sole();
    $verificacion = json_decode((string) $fila->verificacion_identidad, true);

    expect($verificacion['metodo'])->toBe('cedula_presencial')
        ->and($verificacion['evidencia'])->toBe([])
        // Y no queda rastro del RUN en ninguna parte de la columna, que es lo
        // que se estaba comprando con toda esta maniobra.
        ->and((string) $fila->verificacion_identidad)->not->toContain('11.111.111-1');
});

it('la evidencia se vacía también en las claves que nadie declaró', function () {
    // El adoptante escribe la evidencia: `ResultadoVerificacion` la recibe como
    // array libre y ahí puede venir cualquier cosa, anidada. Se vacía la clave
    // entera, no se recorre buscando lo que parezca un RUT.
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Quiero mi ficha',
        new ResultadoVerificacion(true, 'clave_unica', [
            'run' => '11.111.111-1',
            'respuesta' => ['nombre' => 'Rocío Paredes', 'direccion' => 'Los Aromos 123'],
        ]),
    );

    app(Bitacora::class)->desvincular($this->titular);

    $fila = DB::table('privacidad_solicitudes')->where('id', $solicitud->getKey())->sole();

    expect(json_decode((string) $fila->verificacion_identidad, true))
        ->toBe(['metodo' => 'clave_unica', 'evidencia' => []]);
});

it('una verificación ilegible se reescribe purgada en vez de quedar intacta', function () {
    // El módulo no es el único que escribe esa columna: una migración de datos
    // de un adoptante puede dejar algo que no es JSON. Equivocarse conservando
    // deja el RUN vivo en una fila ya huérfana; se reescribe.
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Quiero mi ficha',
        new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']),
    );

    // Por query builder, sin pasar por el cast del modelo: es la única forma de
    // dejar la columna con contenido que el módulo no habría escrito.
    DB::table('privacidad_solicitudes')
        ->where('id', $solicitud->getKey())
        ->update(['verificacion_identidad' => '"11.111.111-1"']);

    app(Bitacora::class)->desvincular($this->titular);

    $fila = DB::table('privacidad_solicitudes')->where('id', $solicitud->getKey())->sole();

    expect(json_decode((string) $fila->verificacion_identidad, true))->toBe(['evidencia' => []]);
});
