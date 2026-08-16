<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\Edades;
use Muni\Shared\Privacidad\EdadNoAcreditada;
use Muni\Shared\Privacidad\FinalidadInvalida;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Solicitante;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
    ]);
});

it('reconoce a un menor de edad', function () {
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    expect(app(Edades::class)->esNNA($nna))->toBeTrue();
});

it('reconoce a un adulto', function () {
    $adulto = PersonaDePrueba::create([
        'nombre' => 'Adulta',
        'documento' => '22.222.222-2',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    expect(app(Edades::class)->esNNA($adulto))->toBeFalse();
});

it('el día en que se cumplen 18 años ya no es NNA', function () {
    // El límite se comprueba porque es donde un off-by-one cambia el régimen
    // jurídico de una persona real, y `age` de Carbon se apoya en la zona
    // horaria de la aplicación.
    $recienMayor = PersonaDePrueba::create([
        'nombre' => 'Recién mayor',
        'documento' => '44.444.444-4',
        'fecha_nacimiento' => now()->subYears(18)->toDateString(),
    ]);

    $vispera = PersonaDePrueba::create([
        'nombre' => 'Víspera',
        'documento' => '55.555.555-5',
        'fecha_nacimiento' => now()->subYears(18)->addDay()->toDateString(),
    ]);

    expect(app(Edades::class)->esNNA($recienMayor))->toBeFalse()
        ->and(app(Edades::class)->esNNA($vispera))->toBeTrue();
});

it('la frontera se cruza en la zona horaria del municipio, no en la del valor recibido', function () {
    // El caso que el fixture no puede ejercitar: `fecha_nacimiento` está
    // casteada por Eloquent y sale siempre en la zona de la aplicación, así que
    // ninguna prueba que pase por el modelo ve este defecto. Un adoptante que
    // hidrate la fecha desde el maestro federado de personas como
    // `DateTimeImmutable` en UTC sí lo ve: durante las últimas tres horas antes
    // del cumpleaños número 18, `Carbon::instance($fecha)->age` comparaba contra
    // el «ahora» de UTC, donde ya es el día siguiente, y convertía a un menor en
    // adulto.
    config(['app.timezone' => 'America/Santiago']);
    Carbon::setTestNow(Carbon::parse('2026-06-15 21:00:00', 'America/Santiago'));

    $enSantiago = new class extends PersonaDePrueba
    {
        public function fechaNacimientoTitular(): ?DateTimeInterface
        {
            return new DateTimeImmutable('2008-06-16', new DateTimeZone('America/Santiago'));
        }
    };

    $enUtc = new class extends PersonaDePrueba
    {
        public function fechaNacimientoTitular(): ?DateTimeInterface
        {
            return new DateTimeImmutable('2008-06-16', new DateTimeZone('UTC'));
        }
    };

    // La misma fecha de calendario tiene que dar el mismo régimen jurídico
    // venga en la zona que venga: la víspera del cumpleaños, todavía es NNA.
    expect(app(Edades::class)->esNNA($enSantiago))->toBeTrue()
        ->and(app(Edades::class)->esNNA($enUtc))->toBeTrue();

    // Y al día siguiente, en Santiago, deja de serlo por ambos caminos.
    Carbon::setTestNow(Carbon::parse('2026-06-16 09:00:00', 'America/Santiago'));

    expect(app(Edades::class)->esNNA($enSantiago))->toBeFalse()
        ->and(app(Edades::class)->esNNA($enUtc))->toBeFalse();

    Carbon::setTestNow();
});

it('quien nació un 29 de febrero cumple los 18 el 1 de marzo, no el 28', function () {
    // Se fija la conducta DEL MÓDULO, no una certeza jurídica: el art. 48 del
    // Código Civil, aplicado a un plazo de años que empieza un día que el mes
    // final no tiene, admite leerse como que vence el último día de ese mes (28
    // de febrero). El módulo elige el día siguiente, que es el lado
    // conservador: trata al titular como NNA un día más. La duda queda anotada
    // en docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md.
    config(['app.timezone' => 'America/Santiago']);

    $bisiesto = new class extends PersonaDePrueba
    {
        public function fechaNacimientoTitular(): ?DateTimeInterface
        {
            return new DateTimeImmutable('2008-02-29', new DateTimeZone('America/Santiago'));
        }
    };

    Carbon::setTestNow(Carbon::parse('2026-02-28 12:00:00', 'America/Santiago'));
    expect(app(Edades::class)->esNNA($bisiesto))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-03-01 12:00:00', 'America/Santiago'));
    expect(app(Edades::class)->esNNA($bisiesto))->toBeFalse();

    Carbon::setTestNow();
});

it('sin fecha de nacimiento devuelve null, que NO es adulto', function () {
    $sinFecha = PersonaDePrueba::create(['nombre' => 'Sin fecha', 'documento' => '33.333.333-3']);

    expect(app(Edades::class)->esNNA($sinFecha))->toBeNull();
});

it('el consentimiento de un NNA lo otorga su representante legal, no él mismo', function () {
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $nna,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
        ['otorgado_por' => Solicitante::Titular],
    ))->toThrow(EdadNoAcreditada::class);

    $ok = app(Consentimientos::class)->otorgar(
        $nna,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
        ['otorgado_por' => Solicitante::RepresentanteLegal],
    );

    expect($ok->exists)->toBeTrue()
        ->and($ok->otorgado_por)->toBe(Solicitante::RepresentanteLegal);
});

it('un menor tampoco consiente por omisión de otorgado_por', function () {
    // El caso realmente peligroso: nadie escribe `Solicitante::Titular`, se
    // omite la opción y el default del servicio la pone. Si la comprobación
    // mirara solo lo que viene en $opciones, este camino colaría.
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $nna,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
    ))->toThrow(EdadNoAcreditada::class);

    expect(Consentimiento::query()->count())->toBe(0);
});

it('un menor tampoco consiente vía apoderado: no puede otorgar mandato', function () {
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $nna,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
        ['otorgado_por' => Solicitante::Apoderado],
    ))->toThrow(EdadNoAcreditada::class);
});

it('acepta el representante legal escrito como string, que es lo que el cast admite', function () {
    // `otorgado_por` está casteada al enum, así que un adoptante puede pasar el
    // string y la fila se crea igual. Si la comprobación de edad comparara solo
    // contra la instancia del enum, ese camino legítimo quedaría rechazado.
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    $ok = app(Consentimientos::class)->otorgar(
        $nna,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
        ['otorgado_por' => 'representante_legal'],
    );

    expect($ok->otorgado_por)->toBe(Solicitante::RepresentanteLegal);
});

it('rechaza pedir consentimiento a quien no tiene la edad acreditada', function () {
    $sinFecha = PersonaDePrueba::create(['nombre' => 'Sin fecha', 'documento' => '33.333.333-3']);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $sinFecha,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
    ))->toThrow(EdadNoAcreditada::class);

    // Ni siquiera con el representante legal: el problema no es quién firma,
    // es que no se sabe si hace falta que firme un tercero.
    expect(fn () => app(Consentimientos::class)->otorgar(
        $sinFecha,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
        ['otorgado_por' => Solicitante::RepresentanteLegal],
    ))->toThrow(EdadNoAcreditada::class);
});

it('una finalidad que no admite NNA los rechaza aunque consienta el representante legal', function () {
    $this->finalidad->update(['admite_nna' => false]);

    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $nna,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
        ['otorgado_por' => Solicitante::RepresentanteLegal],
    ))->toThrow(FinalidadInvalida::class);
});

it('una finalidad que no admite NNA sigue sirviendo a los adultos', function () {
    $this->finalidad->update(['admite_nna' => false]);

    $adulto = PersonaDePrueba::create([
        'nombre' => 'Adulta',
        'documento' => '22.222.222-2',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    expect(app(Consentimientos::class)->otorgar(
        $adulto,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
    )->exists)->toBeTrue();
});

it('las finalidades ya existentes admiten NNA salvo que se diga lo contrario', function () {
    // El default es true a propósito: apagarlo retroactivamente dejaría sin
    // base a los consentimientos ya otorgados de un registro comunal que trata
    // menores desde antes de esta migración.
    //
    // Se inserta por query builder, saltándose el modelo, porque el default
    // vive en dos lados y hay que comprobar el de la columna —el que aplica a
    // las filas que ya existían cuando corrió la migración—, no el de
    // `$attributes`, que solo cubre las instancias nuevas.
    DB::table('privacidad_finalidades')->insert([
        'sistema' => 'discapacidad',
        'codigo' => 'preexistente',
        'nombre' => 'Finalidad de antes de esta columna',
        'base_licitud' => BaseLicitud::Consentimiento->value,
        'es_accesoria' => true,
    ]);

    expect(Finalidad::where('codigo', 'preexistente')->sole()->admite_nna)->toBeTrue()
        // Y la instancia recién creada por el modelo, que es el otro camino.
        ->and($this->finalidad->fresh()->admite_nna)->toBeTrue()
        ->and($this->finalidad->admite_nna)->toBeTrue();
});

it('el adulto sigue otorgando su consentimiento sin fricción', function () {
    $adulto = PersonaDePrueba::create([
        'nombre' => 'Adulta',
        'documento' => '22.222.222-2',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    expect(app(Consentimientos::class)->otorgar(
        $adulto,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
    )->exists)->toBeTrue();
});

it('un titular que no implementa el contrato no pasa por el régimen de NNA', function () {
    // La firma de otorgar() admite cualquier Model. Quien no implementa
    // TitularDeDatos no tiene fecha de nacimiento que preguntar, y bloquearlo
    // sería inventar una exigencia que el contrato no le hizo.
    $ajeno = new class extends Model
    {
        protected $table = 'personas_de_prueba';

        protected $guarded = [];
    };

    $ajeno->forceFill(['nombre' => 'Modelo ajeno'])->save();

    expect(app(Consentimientos::class)->otorgar(
        $ajeno,
        $this->finalidad,
        MedioDeConsentimiento::FirmaPapel,
    )->exists)->toBeTrue();
});
