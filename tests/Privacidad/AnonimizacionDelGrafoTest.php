<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\ExportacionDeDatos;
use Muni\Shared\Privacidad\Informaciones;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Rectificaciones;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

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

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subYears(6),
    ]);

    // Control: una persona vigente, con la misma historia, que no debe perder
    // nada. Un barrido que se lleve de más es tan defectuoso como uno que deje.
    $this->vigente = PersonaDePrueba::create([
        'nombre' => 'Otro Titular',
        'documento' => '22.222.222-2',
        'tratamiento_iniciado_en' => now(),
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

    app(Textos::class)->publicar('aviso_recoleccion', 'Sus datos se tratan para…');

    // Historia completa de las dos personas: solicitud, exportación,
    // rectificación, consentimiento, información entregada y bitácora suelta.
    $historia = function (PersonaDePrueba $persona) {
        $verificacion = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => $persona->documento]);

        $acceso = app(Solicitudes::class)->registrar(
            $persona, TipoDeSolicitud::Acceso, 'Quiero saber qué tienen de mí', $verificacion,
        );
        app(ExportacionDeDatos::class)->paraSolicitud($acceso);

        $rectificacion = app(Solicitudes::class)->registrar(
            $persona, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal escrito', $verificacion,
        );
        app(Rectificaciones::class)->aplicar(
            $rectificacion, ['nombre' => $persona->nombre.' Soto'], 'Se corrigió con la cédula a la vista',
        );

        app(Consentimientos::class)->otorgar($persona, $this->accesoria, MedioDeConsentimiento::FirmaPapel);
        app(Informaciones::class)->registrar($persona, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel);
        app(RegistroDeEvidencia::class)->registrar('ficha.consultada', ['pantalla' => 'detalle'], $persona);
    };

    $historia($this->titular);
    $historia($this->vigente);

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

it('la historia de la persona vigente no se toca', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $intactas = collect(tablasDelModuloConTitular())
        ->mapWithKeys(fn (string $tabla) => [$tabla => DB::table($tabla)
            ->where('titular_id', $this->vigente->getKey())
            ->whereNull('titular_ref')
            ->count(), ]);

    expect($intactas->get('privacidad_solicitudes'))->toBe(2)
        ->and($intactas->get('privacidad_consentimientos'))->toBe(1)
        ->and($intactas->get('privacidad_informaciones'))->toBe(1)
        ->and($intactas->get('privacidad_bitacora'))->toBeGreaterThan(5)
        ->and($this->vigente->refresh()->documento)->toBe('22.222.222-2');
});
