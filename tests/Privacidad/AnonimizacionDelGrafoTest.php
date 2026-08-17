<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\ExportacionDeDatos;
use Muni\Shared\Privacidad\Informaciones;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Rectificaciones;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitante;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\SupresionSoloLocal;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;
use Muni\Shared\Tests\Privacidad\Fixtures\UsuarioDePrueba;

/**
 * Anonimizar es una propiedad del grafo, no de una fila: mientras CUALQUIER
 * tabla del módulo conserve el id de la persona, la anonimización es decorativa
 * —desde una entrada huérfana se salta por `datos->solicitud_id` a la solicitud,
 * y de su titular_id al maestro de personas federado—.
 *
 * Por eso este archivo no comprueba tabla por tabla a mano: lee el esquema,
 * junta TODAS las tablas del módulo que guardan un titular y exige que ninguna
 * resuelva. Cuando un ciclo futuro agregue otra tabla con morph al titular, el
 * barrido tiene que crecer con ella o esto se pone rojo.
 *
 * @return list<string>
 */
function tablasDelModuloConTitular(): array
{
    return collect(Schema::getTables())
        ->pluck('name')
        // Todas las tablas del módulo llevan este prefijo; el resto del esquema
        // es del sistema consumidor y no lo barre este paquete.
        ->filter(fn (string $tabla) => str_starts_with($tabla, 'privacidad_'))
        ->filter(fn (string $tabla) => in_array('titular_id', Schema::getColumnListing($tabla), true))
        ->sort()
        ->values()
        ->all();
}

/**
 * Columnas de texto libre (varchar/text/json) de cada tabla barrida, leídas del
 * esquema: son las que pueden traer un identificador escrito adentro, que es lo
 * que cortar punteros no resuelve.
 *
 * @return array<string, list<string>>
 */
function columnasDeTextoLibre(): array
{
    $tiposDeTexto = ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'json', 'jsonb'];

    return collect(tablasDelModuloConTitular())
        ->mapWithKeys(fn (string $tabla) => [$tabla => collect(Schema::getColumns($tabla))
            ->filter(fn (array $columna) => in_array(strtolower((string) $columna['type_name']), $tiposDeTexto, true))
            ->pluck('name')
            ->sort()
            ->values()
            ->all(), ])
        ->all();
}

/**
 * Columnas de fecha de cada tabla barrida, leídas del esquema.
 *
 * @return array<string, list<string>>
 */
function columnasDeFecha(): array
{
    $tiposDeFecha = ['datetime', 'timestamp', 'date', 'timestamptz', 'datetimetz'];

    return collect(tablasDelModuloConTitular())
        ->mapWithKeys(fn (string $tabla) => [$tabla => collect(Schema::getColumns($tabla))
            ->filter(fn (array $columna) => in_array(strtolower((string) $columna['type_name']), $tiposDeFecha, true))
            ->pluck('name')
            ->sort()
            ->values()
            ->all(), ])
        ->all();
}

/**
 * Columnas de identificador (enteros y uuid) de cada tabla barrida.
 *
 * Van con las de texto en la misma guardia porque el defecto que cierran es el
 * mismo, y este es peor: un `user_id` no lo ve ninguna búsqueda de cadenas, así
 * que puede quedar apuntando a la persona durante ciclos sin que nada chille.
 *
 * @return array<string, list<string>>
 */
function columnasDeIdentificador(): array
{
    $tiposDeId = ['integer', 'bigint', 'smallint', 'int', 'int8', 'int4', 'uuid', 'guid'];

    return collect(tablasDelModuloConTitular())
        ->mapWithKeys(fn (string $tabla) => [$tabla => collect(Schema::getColumns($tabla))
            ->filter(fn (array $columna) => in_array(strtolower((string) $columna['type_name']), $tiposDeId, true))
            ->pluck('name')
            ->sort()
            ->values()
            ->all(), ])
        ->all();
}

beforeEach(function () {
    // 'disco_evidencia' ya no tiene default (ver config/privacidad.php): la
    // historia sembrada abajo siempre deja evidencia_path/respuesta_path/
    // acreditacion_path seteados, así que sin esto cualquier desvincular()
    // de este archivo truena con DiscoEvidenciaNoConfigurado.
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.disco_evidencia' => 'local']);

    // Declaración obligatoria desde que la supresión se propaga al maestro de
    // personas: sin ella, `AplicarRetencion` se niega a ejecutar. Acá se declara
    // «solo local» porque lo que este archivo mide es el grafo del sistema, no
    // la federación.
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    // El caso que hace peligrosos a los `user_*`: un adoptante con portal
    // ciudadano, donde quien está autenticado es el propio titular. El módulo
    // guarda Auth::id() sin preguntar, así que ese id ES la persona.
    $this->usuario = new UsuarioDePrueba(['id' => 77]);
    $this->actingAs($this->usuario);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subYears(6),
        // Adulta: desde el régimen de NNA, otorgar() exige la edad acreditada.
        // La fecha vive en `personas_de_prueba`, tabla del sistema adoptante:
        // no la barre este módulo, y la anonimización de la persona sigue
        // siendo responsabilidad de su propio anonimizar().
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    // Control: una persona vigente, con la misma historia, que no debe perder
    // nada. Un barrido que se lleve de más es tan defectuoso como uno que deje.
    $this->vigente = PersonaDePrueba::create([
        'nombre' => 'Otro Titular',
        'documento' => '22.222.222-2',
        'tratamiento_iniciado_en' => now(),
        'fecha_nacimiento' => now()->subYears(35)->toDateString(),
    ]);

    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'atencion',
        'nombre' => 'Atención de casos',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 60,
    ]);

    $this->accesoria = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión de actividades',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
    ]);

    $this->texto = app(Textos::class)->publicar('aviso_recoleccion', 'Sus datos se tratan para…');
    $this->textoConsentimiento = app(Textos::class)->publicar('consentimiento_difusion', 'Autorizo la difusión…');

    // Historia completa de las dos personas: solicitud, exportación,
    // rectificación, consentimiento, información entregada y bitácora suelta.
    //
    // Los textos van sembrados con identificadores reales a propósito —el RUT
    // dentro del relato, el nombre en la resolución, el RUT en el nombre del
    // archivo de respuesta, la IP del formulario—, porque es lo que hacen los
    // sistemas de verdad y es exactamente lo que tiene que no sobrevivir.
    $historia = function (PersonaDePrueba $persona, string $ip, string $direccion) {
        $rut = (string) $persona->documento;
        $verificacion = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => $rut]);

        $acceso = app(Solicitudes::class)->registrar(
            $persona,
            TipoDeSolicitud::Acceso,
            "Mi RUT es {$rut} y vivo en {$direccion}: quiero saber qué tienen de mí",
            $verificacion,
        );
        app(ExportacionDeDatos::class)->paraSolicitud($acceso);
        app(Solicitudes::class)->acoger(
            $acceso,
            "Se entregó el expediente completo a {$persona->nombre} en el mesón",
            'respuestas/'.str_replace(['.', '-'], '', $rut).'.pdf',
        );

        // La presenta un apoderado: así la historia sembrada cubre también
        // `privacidad_solicitudes.acreditacion_path`, que es un documento de un
        // tercero y tiene que borrarse del disco como el resto.
        $rectificacion = app(Solicitudes::class)->registrar(
            $persona, TipoDeSolicitud::Rectificacion, "Mi apellido está mal escrito, soy {$persona->nombre}", $verificacion,
            Solicitante::Apoderado,
            'acreditaciones/mandato-'.str_replace(['.', '-'], '', $rut).'.pdf',
        );
        app(Rectificaciones::class)->aplicar(
            $rectificacion, ['nombre' => $persona->nombre.' Soto'], "Se corrigió con la cédula de {$persona->nombre} a la vista",
        );

        // Lo otorga un apoderado con mandato: así la historia sembrada cubre
        // también `acreditacion_path`, que es un documento DE UN TERCERO y por
        // eso tiene que borrarse del disco igual que el consentimiento firmado.
        app(Consentimientos::class)->otorgar($persona, $this->accesoria, MedioDeConsentimiento::FirmaPapel, [
            'evidencia_path' => 'consentimientos/'.str_replace(['.', '-'], '', $rut).'.pdf',
            'acreditacion_path' => 'acreditaciones/'.str_replace(['.', '-'], '', $rut).'.pdf',
            'otorgado_por' => Solicitante::Apoderado,
            'texto' => $this->textoConsentimiento,
            'ip' => $ip,
        ]);
        app(Informaciones::class)->registrar($persona, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel, [
            'ip' => $ip,
        ]);
        app(RegistroDeEvidencia::class)->registrar('ficha.consultada', ['pantalla' => 'detalle'], $persona);
    };

    // Direcciones distintas a propósito: si las dos personas compartieran el
    // texto, la búsqueda de identificadores del test no podría distinguir un
    // dato que sobrevivió de uno que es de la persona vigente.
    // La historia ocurre meses antes de la anonimización, como en la vida real.
    // Importa para el test: una fila escrita en el mismo segundo que la
    // anonimización queda huérfana SIN referencia de grupo, justamente porque su
    // fecha delataría el instante en que se anonimizó a la persona.
    $this->travelTo(now()->subMonths(2));
    $historia($this->titular, $this->ip = '192.168.10.44', $this->direccion = 'Av. Freire 123');
    $historia($this->vigente, '192.168.10.99', 'Pasaje Los Aromos 987');
    $this->travelBack();

    $vencido = $this->titular->getKey();

    app()->bind(ResuelveTitularesVencidos::class, fn () => new class($vencido) implements ResuelveTitularesVencidos
    {
        public function __construct(private readonly int $vencido) {}

        public function vencidos(Finalidad $finalidad): iterable
        {
            return PersonaDePrueba::query()->whereKey($this->vencido)->get();
        }
    });
});

it('el barrido conoce exactamente las tablas del esquema que guardan un titular', function () {
    // Esta es la guardia que hace repetible al resto: si un ciclo futuro crea
    // otra tabla del módulo con morph al titular y no la agrega a Bitacora, acá
    // se ve la diferencia. Sin esto, las aserciones de abajo pasarían en vacío
    // sobre la tabla nueva, porque este test no le escribe filas.
    /** @var array<string, mixed> $declaradas */
    $declaradas = (new ReflectionClass(Bitacora::class))->getConstant('TABLAS');
    $declaradas = array_keys($declaradas);
    sort($declaradas);

    expect(tablasDelModuloConTitular())->toBe($declaradas);
});

it('las rutas de documento que se anulan son exactamente las que se borran del disco', function () {
    // Tercera guardia de deriva, hermana de las dos de arriba y del mismo tipo
    // de defecto: TABLAS y ARCHIVOS son dos listas que hay que mover juntas y
    // nada obligaba a hacerlo. Si se agrega una columna de ruta a TABLAS y no a
    // ARCHIVOS, la anonimización anula la ruta y deja el PDF vivo en disco, sin
    // dueño y sin forma de encontrarlo —el defecto que ARCHIVOS existe para
    // impedir—. Al revés, una columna en ARCHIVOS que TABLAS ya no anula borra
    // el archivo y deja la ruta apuntando al vacío.
    /** @var array<string, array<string, mixed>> $tablas */
    $tablas = (new ReflectionClass(Bitacora::class))->getConstant('TABLAS');
    /** @var array<string, list<string>> $archivos */
    $archivos = (new ReflectionClass(Bitacora::class))->getConstant('ARCHIVOS');

    $enArchivos = collect($archivos)
        ->flatMap(fn (array $columnas, string $tabla) => collect($columnas)->map(fn (string $c) => "{$tabla}.{$c}"))
        ->sort()->values()->all();

    // Se reconoce una ruta por el sufijo del nombre, que es la convención del
    // esquema (`respuesta_path`, `evidencia_path`). Una columna de ruta que no
    // lo siga se escapa de esta guardia: si algún día existe, va nombrada acá.
    $rutasEnTablas = collect($tablas)
        ->flatMap(fn (array $columnas, string $tabla) => collect(array_keys($columnas))
            ->filter(fn (string $c) => str_ends_with($c, '_path'))
            ->map(fn (string $c) => "{$tabla}.{$c}"))
        ->sort()->values()->all();

    expect($enArchivos)->toBe($rutasEnTablas)
        // Y que no sea una comparación de dos listas vacías.
        ->and($enArchivos)->toContain('privacidad_solicitudes.respuesta_path');

    // Además: lo que se borra tiene que quedar anulado, no con centinela. Una
    // ruta con «[suprimido al anonimizar]» no es una ruta.
    foreach ($archivos as $tabla => $columnas) {
        foreach ($columnas as $columna) {
            expect(array_key_exists($columna, $tablas[$tabla] ?? []))->toBeTrue()
                ->and($tablas[$tabla][$columna])->toBeNull();
        }
    }
});

it('la retención borra del disco la respuesta al titular y el consentimiento firmado', function () {
    // El camino de producción: hasta ahora el borrado solo se ejercitaba
    // llamando a Bitacora::desvincular() a mano, y solo sobre `evidencia_path`.
    // Sacar `respuesta_path` de ARCHIVOS dejaba la suite entera en verde con el
    // expediente del titular vivo en disco.
    Storage::fake('local');

    $rut = str_replace(['.', '-'], '', (string) $this->titular->documento);
    Storage::disk('local')->put("respuestas/{$rut}.pdf", 'expediente completo');
    Storage::disk('local')->put("consentimientos/{$rut}.pdf", 'firma escaneada');
    Storage::disk('local')->put("acreditaciones/{$rut}.pdf", 'mandato del apoderado');
    Storage::disk('local')->put("acreditaciones/mandato-{$rut}.pdf", 'mandato de la rectificación');
    // El de la persona vigente: el barrido no puede llevárselo por delante.
    Storage::disk('local')->put('respuestas/222222222.pdf', 'expediente de la vigente');

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    Storage::disk('local')->assertMissing("respuestas/{$rut}.pdf");
    Storage::disk('local')->assertMissing("consentimientos/{$rut}.pdf");
    Storage::disk('local')->assertMissing("acreditaciones/{$rut}.pdf");
    Storage::disk('local')->assertMissing("acreditaciones/mandato-{$rut}.pdf");
    Storage::disk('local')->assertExists('respuestas/222222222.pdf');

    $corrida = EntradaBitacora::where('evento', 'retencion.constancia')->sole();

    expect($corrida->datos['archivos_suprimidos'])->toBe(4)
        ->and($corrida->datos['archivos_no_encontrados'])->toBe(0);
});

it('después de anonimizar, ninguna tabla del módulo resuelve al titular', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $conRastro = [];

    foreach (tablasDelModuloConTitular() as $tabla) {
        $rastros = DB::table($tabla)
            ->where('titular_type', $this->titular->getMorphClass())
            ->where('titular_id', $this->titular->getKey())
            ->count();

        if ($rastros > 0) {
            $conRastro[] = $tabla;
        }
    }

    // Se comparan listas y no contadores para que el rojo diga QUÉ tabla quedó
    // apuntando a la persona.
    expect($conRastro)->toBe([])
        // Y la historia sigue existiendo: anonimizar no es borrar la evidencia.
        ->and(DB::table('privacidad_solicitudes')->whereNull('titular_id')->count())->toBe(2)
        ->and(DB::table('privacidad_consentimientos')->whereNull('titular_id')->count())->toBe(1)
        ->and(DB::table('privacidad_informaciones')->whereNull('titular_id')->count())->toBe(1)
        ->and(EntradaBitacora::whereNull('titular_id')->whereNotNull('titular_ref')->count())
        ->toBeGreaterThan(5);
});

it('ningún dato de una fila huérfana vuelve a una solicitud con titular vivo', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    // `datos` va en texto plano y cuatro eventos escriben ahí el solicitud_id:
    // era el puntero de vuelta a la persona. Sigue estando —sirve para agrupar
    // el caso— pero ya no resuelve a nadie.
    $solicitudIds = EntradaBitacora::query()
        ->whereNull('titular_id')
        ->get()
        ->pluck('datos.solicitud_id')
        ->filter()
        ->unique()
        ->values();

    // Si esto quedara vacío, el test estaría pasando sin probar nada.
    expect($solicitudIds)->toHaveCount(2)
        ->and(DB::table('privacidad_solicitudes')
            ->whereIn('id', $solicitudIds->all())
            ->whereNotNull('titular_id')
            ->count())->toBe(0);
});

it('el hash del identificador tampoco sobrevive en los consentimientos', function () {
    // vigente_clave es sha1(morph|id|finalidad): con la lista de ids del
    // municipio se revierte por fuerza bruta, así que dejarlo equivale a dejar
    // el titular_id.
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $delTitular = DB::table('privacidad_consentimientos')->whereNull('titular_id')->first();
    $delVigente = DB::table('privacidad_consentimientos')->whereNotNull('titular_id')->first();

    expect($delTitular->vigente_clave)->toBeNull()
        ->and($delTitular->vigente_clave)
        ->not->toBe(sha1($this->titular->getMorphClass().'|'.$this->titular->getKey().'|'.$this->accesoria->getKey()))
        // El de la persona vigente sigue intacto: el barrido no se lleva de más.
        ->and($delVigente->vigente_clave)->not->toBeNull();
});

it('todas las filas del titular quedan bajo una única referencia opaca', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $refs = collect(tablasDelModuloConTitular())
        ->flatMap(fn (string $tabla) => DB::table($tabla)->whereNotNull('titular_ref')->pluck('titular_ref'))
        ->unique()
        ->values();

    expect($refs)->toHaveCount(1)
        ->and(strlen((string) $refs->first()))->toBe(32);
});

it('ninguna columna de una tabla barrida conserva un identificador del titular', function () {
    // Esta es la aserción que importa. Las de arriba comprueban que se cortaron
    // los punteros; esta comprueba la propiedad de verdad —que no sobrevive
    // nada que identifique a la persona—, que es la que el fix anterior no
    // tenía: un RUT en claro dentro de `verificacion_identidad` identifica
    // igual que un titular_id, y no hace falta ni un join para leerlo.
    $identificadores = collect([
        (string) $this->titular->documento,                                  // el RUT como lo escribió el mesón
        str_replace(['.', '-'], '', (string) $this->titular->documento),     // y como quedó en el nombre del archivo
        'Rocío Paredes',
        $this->direccion,
        // Un sha256 de IP no es anonimato: son 2^32 direcciones, se recorren
        // enteras en un rato con un diccionario precalculado.
        hash('sha256', $this->ip),
        sha1($this->titular->getMorphClass().'|'.$this->titular->getKey().'|'.$this->accesoria->getKey()),
    ])
        // Cada uno también en su forma escapada: la bitácora guarda `datos`
        // como JSON, donde «Rocío» viaja como «Rocío». Buscar solo la forma
        // legible dejaría pasar justo lo que se quiere detectar.
        ->flatMap(fn (string $id) => [$id, trim((string) json_encode($id), '"')])
        ->unique()
        ->all();

    $buscar = function () use ($identificadores): array {
        $hallazgos = [];

        foreach (tablasDelModuloConTitular() as $tabla) {
            foreach (DB::table($tabla)->get() as $fila) {
                foreach ((array) $fila as $columna => $valor) {
                    if (! is_string($valor)) {
                        continue;
                    }

                    foreach ($identificadores as $identificador) {
                        if (str_contains($valor, $identificador)) {
                            $hallazgos[] = "{$tabla}.{$columna}";
                        }
                    }
                }
            }
        }

        return array_values(array_unique($hallazgos));
    };

    // El test se valida a sí mismo: si la búsqueda no encontrara nada ANTES de
    // anonimizar, tampoco probaría nada después.
    expect($buscar())->not->toBe([]);

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    expect($buscar())->toBe([]);
});

it('el identificador de grupo no viaja junto al instante de la anonimización', function () {
    // Lo que esta prueba acredita, y solo esto: el ref —el único identificador
    // que el módulo publica y que agrupa filas huérfanas— nunca aparece en una
    // fila fechada en el instante de la anonimización. Esa combinación sería
    // llave directa: se buscan las personas anonimizadas por su marca
    // («ANONIMIZADO»), se lee su `updated_at` —congelado, porque nadie vuelve a
    // escribir esa fila—, se cae en la fila del módulo de ese mismo segundo y
    // con su ref se leen todas las demás filas huérfanas, en las cinco tablas.
    // Por eso el ref solo puede vivir en filas cuyas fechas son hechos de
    // negocio corrientes, anteriores a la anonimización.
    //
    // Lo que esta prueba NO acredita, y ninguna prueba de este módulo puede:
    // que el conjunto de filas huérfanas deje de ser atribuible a una persona.
    // Sin tocar el ref quedan dos rutas, ambas con 12 de 12 en el review
    // independiente (40 vecinos, 12 anonimizados en la misma corrida):
    //
    //   1. Fechas de negocio: `personas.created_at` sobrevive —es del adoptante
    //      y nadie la anula— y se empareja por vecino más cercano con la fecha
    //      de negocio más antigua del grupo huérfano (`entregado_en`,
    //      `otorgado_en`, `ocurrido_en`), que sobrevive por ser el hecho
    //      auditable. Aguanta 72 h de ruido.
    //   2. Ids de fila: los grupos huérfanos ordenados por su
    //      `privacidad_bitacora.id` mínimo siguen el mismo orden que las
    //      personas anonimizadas ordenadas por `id`, porque el módulo escribe
    //      siempre después de que la persona existe.
    //
    // Cerrar cualquiera de las dos exige destruir el hecho auditable. Es
    // residuo declarado en el spec de pendientes (5-ter), materia de EIPD.
    $anonimizacion = now()->startOfSecond();

    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $conFechaDelInstante = [];
    $conRef = 0;

    foreach (columnasDeFecha() as $tabla => $columnas) {
        foreach (DB::table($tabla)->whereNotNull('titular_ref')->get() as $fila) {
            $conRef++;

            foreach ($columnas as $columna) {
                if ($fila->{$columna} === null) {
                    continue;
                }

                if (Carbon::parse($fila->{$columna})->greaterThanOrEqualTo($anonimizacion)) {
                    $conFechaDelInstante[] = "{$tabla}.{$columna}";
                }
            }
        }
    }

    // Si ninguna fila llevara ref, el test pasaría sin comprobar nada.
    expect($conRef)->toBeGreaterThan(5)
        ->and(array_values(array_unique($conFechaDelInstante)))->toBe([]);
});

it('toda columna clasificable de una tabla barrida está clasificada', function () {
    // Hermana de la guardia de tablas, un nivel más abajo: si mañana alguien
    // agrega `observaciones` a privacidad_solicitudes y no decide si se purga o
    // se conserva, acá aparece. La aserción de identificadores de arriba no
    // alcanza sola, porque una columna nueva que este test no siembra pasaría
    // en vacío.
    //
    // Cubre texto Y enteros/uuid. Los `user_*` se colaron exactamente por ese
    // hueco: son enteros, no los ve ninguna búsqueda de cadenas, y sobrevivieron
    // dos rondas sin que nadie decidiera nada sobre ellos.
    //
    // Se conservan porque ninguna guarda un valor del titular: el sistema, el
    // nombre del evento, la clase morph, la referencia opaca, las etiquetas
    // categóricas —tipo, estado, medio, quién otorgó; y `version_texto`, que
    // el módulo ya no escribe y solo conserva las filas anteriores a
    // `texto_id`: se sigue clasificando porque la columna sigue existiendo y lo
    // que guarda es una etiqueta de versión, no un dato del titular; las dos
    // de «quién actúa» respaldadas por el enum Solicitante, que es lo que impide
    // que esta lista termine bendiciendo un nombre de persona— y las
    // llaves a catálogos del propio módulo (una finalidad o un texto informativo
    // no son una persona). `datos` es la excepción razonada: es la evidencia
    // misma y su invariante es nombres de campo, nunca valores.
    $conservadas = [
        'privacidad_bitacora' => ['datos', 'evento', 'id', 'sistema', 'titular_ref', 'titular_type'],
        'privacidad_solicitudes' => ['estado', 'id', 'sistema', 'solicitante', 'tipo', 'titular_ref', 'titular_type'],
        'privacidad_consentimientos' => [
            'finalidad_id', 'id', 'medio', 'otorgado_por', 'texto_id', 'titular_ref', 'titular_type', 'version_texto',
        ],
        'privacidad_informaciones' => ['id', 'medio', 'sistema', 'texto_id', 'titular_ref', 'titular_type'],
        // finalidad_id y solicitud_id se conservan por el mismo criterio que
        // finalidad_id en privacidad_consentimientos: son llaves a catálogos
        // del propio módulo, no a la persona. Ver el docblock de TABLAS en
        // Bitacora.
        'privacidad_bloqueos' => ['finalidad_id', 'id', 'sistema', 'solicitud_id', 'titular_ref', 'titular_type'],
    ];

    /** @var array<string, array<string, mixed>> $tablas */
    $tablas = (new ReflectionClass(Bitacora::class))->getConstant('TABLAS');

    $purgadas = collect($tablas)->map(
        // La clave puede venir con ruta JSON («verificacion_identidad->evidencia»);
        // lo clasificado es la columna.
        fn (array $columnas) => collect(array_keys($columnas))->map(fn (string $c) => Str::before($c, '->'))->all(),
    );

    $sinClasificar = [];

    foreach (columnasDeTextoLibre() as $tabla => $columnas) {
        // `titular_id` no está en TABLAS porque lo anula el propio barrido, en
        // las dos ramas de la ventana: es su objetivo, no una columna más.
        $clasificadas = array_merge($conservadas[$tabla] ?? [], $purgadas->get($tabla, []), ['titular_id']);

        foreach (array_merge($columnas, columnasDeIdentificador()[$tabla] ?? []) as $columna) {
            if (! in_array($columna, $clasificadas, true)) {
                $sinClasificar[] = "{$tabla}.{$columna}";
            }
        }
    }

    expect(array_values(array_unique($sinClasificar)))->toBe([]);
});

it('los ids de usuario no sobreviven en las filas del titular anonimizado', function () {
    // En un portal ciudadano ese entero es la cuenta del propio titular, y el
    // módulo no puede distinguirlo del id de un funcionario: guarda Auth::id()
    // sin preguntar. Conservarlo dejaría un puntero directo a la persona que
    // ninguna guardia de texto ve.
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $conUsuario = [];

    foreach (columnasDeIdentificador() as $tabla => $columnas) {
        foreach (array_filter($columnas, fn (string $c) => str_starts_with($c, 'user_')) as $columna) {
            $vivos = DB::table($tabla)->whereNull('titular_id')->whereNotNull($columna)->count();

            if ($vivos > 0) {
                $conUsuario[] = "{$tabla}.{$columna}";
            }
        }
    }

    expect($conUsuario)->toBe([])
        // Y en las filas de la persona vigente el id sigue: la trazabilidad del
        // funcionario se pierde solo donde ya no hay a quién trazar.
        ->and(DB::table('privacidad_solicitudes')
            ->where('titular_id', $this->vigente->getKey())
            ->whereNotNull('user_registro_id')
            ->count())->toBe(2);
});

it('el hecho auditable sobrevive a la purga', function () {
    // Suprimir de más también es un defecto: sin tipo, estado y fechas, la
    // bitácora deja de servir para lo que existe —acreditar ante la Agencia que
    // la solicitud se recibió y se resolvió dentro del plazo—.
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $rectificacion = DB::table('privacidad_solicitudes')
        ->whereNull('titular_id')->where('tipo', TipoDeSolicitud::Rectificacion->value)->sole();

    $verificacion = json_decode((string) $rectificacion->verificacion_identidad, true);

    expect($rectificacion->estado)->toBe('acogida')
        ->and($rectificacion->recibida_en)->not->toBeNull()
        ->and($rectificacion->vence_en)->not->toBeNull()
        ->and($rectificacion->resuelta_en)->not->toBeNull()
        ->and($rectificacion->titular_ref)->not->toBeNull()
        // El texto libre se fue, pero deja marca de que hubo algo.
        ->and($rectificacion->detalle)->toBe(Bitacora::SUPRIMIDO)
        ->and($rectificacion->fundamento_resolucion)->toBeNull()
        // Cómo se verificó la identidad es hecho auditable; el RUN que iba
        // adentro, no.
        ->and($verificacion['metodo'])->toBe('cedula_presencial')
        ->and($verificacion['evidencia'])->toBe([]);
});

it('la historia de la persona vigente no se toca', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $intactas = collect(tablasDelModuloConTitular())
        ->mapWithKeys(fn (string $tabla) => [$tabla => DB::table($tabla)
            ->where('titular_id', $this->vigente->getKey())
            ->whereNull('titular_ref')
            ->count(), ]);

    $suya = DB::table('privacidad_solicitudes')->where('titular_id', $this->vigente->getKey())->first();

    expect($intactas->get('privacidad_solicitudes'))->toBe(2)
        ->and($intactas->get('privacidad_consentimientos'))->toBe(1)
        ->and($intactas->get('privacidad_informaciones'))->toBe(1)
        ->and($intactas->get('privacidad_bitacora'))->toBeGreaterThan(5)
        ->and($this->vigente->refresh()->documento)->toBe('22.222.222-2')
        // Y su texto libre sigue completo: la purga es del titular anonimizado,
        // no del que sigue vigente.
        ->and($suya->detalle)->toContain('22.222.222-2')
        ->and($suya->verificacion_identidad)->toContain('22.222.222-2');
});
