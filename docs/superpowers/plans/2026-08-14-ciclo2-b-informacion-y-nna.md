# Ciclo 2 — Plan B: deber de información, prueba del consentimiento y régimen de NNA

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cubrir las dos obligaciones que más veces se ejercen y de las que no había nada: informar al titular al recoger sus datos (y poder probar qué texto vio), y el régimen reforzado de niños, niñas y adolescentes.

**Architecture:** Los huecos 1 y 7 del spec son el mismo problema —textos versionados e inmutables más un registro de entrega— y se construyen juntos. El hueco 2 agrega un método al contrato `TitularDeDatos`, y por eso va pronto: hoy solo un sistema lo implementa, en una rama.

**Tech Stack:** PHP 8.3+, Laravel 11/12/13, Pest 3/4, Orchestra Testbench, SQLite en tests.

**Spec:** `docs/superpowers/specs/2026-08-14-ley-21719-ciclo2-design.md` (huecos 1, 7 y 2)
**Depende de:** Plan A terminado (bitácora append-only y desvinculable).

## Global Constraints

- Namespace `Muni\Shared\Privacidad\`. Tablas con prefijo `privacidad_`.
- Sin dependencia de `filament/filament` ni `spatie/laravel-activitylog`.
- `illuminate/* ^11.0|^12.0|^13.0`, `php ^8.3`. Nada de sintaxis posterior.
- Texto de dominio, tablas, columnas y mensajes en español. Los comentarios explican el *porqué*.
- Tests en Pest, en español (`it('...')`).
- Excepciones de dominio propias, nunca `RuntimeException` pelada.
- Servicios que mutan y registran evidencia envuelven ambas cosas en `DB::transaction()`.
- Cada tarea termina con `vendor/bin/pest` y `vendor/bin/pint --test` en verde.
- Commits en español, sin atribución a IA. **No pushear, no mergear, no etiquetar, no tocar ningún remoto.**

## Firmas verificadas contra el código (no adivinar)

- `TitularDeDatos` tiene hoy **seis** métodos: `titularNombre`, `titularDocumento`, `exportarDatosPersonales`, `purgarDatosSensibles`, `anonimizar`, `camposRectificables`.
- `MedioDeConsentimiento`: `firma_papel`, `firma_digital`, `verbal_registrada`.
- `Consentimientos::otorgar(Model $titular, Finalidad $finalidad, MedioDeConsentimiento $medio, array $opciones = []): Consentimiento`.
- `privacidad_consentimientos` tiene hoy `version_texto` como string nullable.

## Migraciones ya existentes (no reordenar)

`2026_08_13_00000{1..6}`, `2026_08_14_000001` (titular_ref), `2026_08_14_000002` (excepción dato sensible, del Plan A). Las nuevas van `2026_08_14_0001{1,2,3}`.

---

### Task 1: Textos informativos versionados e inmutables

**Files:**
- Create: `database/migrations/2026_08_14_000111_create_privacidad_textos_table.php`
- Create: `src/Privacidad/Modelos/TextoInformativo.php`
- Create: `src/Privacidad/TextoInmutable.php`
- Create: `src/Privacidad/Textos.php`
- Test: `tests/Privacidad/TextoInformativoTest.php`

**Interfaces:**
- Consumes: nada del Plan A.
- Produces: `TextoInformativo` (tabla `privacidad_textos`); `Textos::publicar(string $codigo, string $contenido): TextoInformativo`; `Textos::vigente(string $codigo): ?TextoInformativo`; `TextoInmutable extends \DomainException`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/TextoInformativoTest.php`:

```php
<?php

use Muni\Shared\Privacidad\Modelos\TextoInformativo;
use Muni\Shared\Privacidad\TextoInmutable;
use Muni\Shared\Privacidad\Textos;

beforeEach(fn () => config(['privacidad.sistema' => 'discapacidad']));

it('publica la primera versión de un texto y la deja vigente', function () {
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'Sus datos se tratan para…');

    expect($texto->version)->toBe(1)
        ->and($texto->vigente_hasta)->toBeNull()
        ->and(app(Textos::class)->vigente('aviso_recoleccion')->is($texto))->toBeTrue();
});

it('publicar de nuevo crea una versión y cierra la anterior, sin borrarla', function () {
    $servicio = app(Textos::class);
    $primera = $servicio->publicar('aviso_recoleccion', 'Texto viejo');

    $segunda = $servicio->publicar('aviso_recoleccion', 'Texto nuevo');

    expect($segunda->version)->toBe(2)
        ->and($servicio->vigente('aviso_recoleccion')->is($segunda))->toBeTrue()
        ->and($primera->fresh()->vigente_hasta)->not->toBeNull()
        // La versión vieja sobrevive: es la prueba de qué aceptó quien la vio.
        ->and(TextoInformativo::count())->toBe(2);
});

it('sella el hash del contenido al publicar', function () {
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'Contenido exacto');

    expect($texto->hash)->toBe(hash('sha256', 'Contenido exacto'));
});

it('rechaza modificar el contenido de un texto ya publicado', function () {
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'Original');

    expect(fn () => $texto->update(['contenido' => 'Alterado']))->toThrow(TextoInmutable::class);
});

it('rechaza borrar un texto publicado', function () {
    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'Original');

    expect(fn () => $texto->delete())->toThrow(TextoInmutable::class);
});

it('los textos de sistemas distintos no se pisan', function () {
    app(Textos::class)->publicar('aviso_recoleccion', 'De discapacidad');
    config(['privacidad.sistema' => 'licencias']);
    $otro = app(Textos::class)->publicar('aviso_recoleccion', 'De licencias');

    expect($otro->version)->toBe(1)
        ->and(app(Textos::class)->vigente('aviso_recoleccion')->contenido)->toBe('De licencias');
});

it('devuelve null cuando el código no existe', function () {
    expect(app(Textos::class)->vigente('inexistente'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/TextoInformativoTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Textos" not found`.

- [ ] **Step 3: Migración**

Crear `database/migrations/2026_08_14_000111_create_privacidad_textos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los textos que se le muestran al titular, versionados.
 *
 * Una fila publicada no se modifica nunca: corregir un texto crea una versión
 * nueva. Si se pudiera editar, no habría forma de acreditar qué leyó realmente
 * la persona que consintió el mes pasado — que es exactamente lo que la ley
 * exige poder probar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_textos', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->string('codigo');
            $table->unsignedInteger('version');
            $table->text('contenido');
            $table->string('hash', 64);
            $table->timestamp('vigente_desde');
            $table->timestamp('vigente_hasta')->nullable();
            $table->timestamps();

            $table->unique(['sistema', 'codigo', 'version']);
            $table->index(['sistema', 'codigo', 'vigente_hasta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_textos');
    }
};
```

- [ ] **Step 4: Excepción**

Crear `src/Privacidad/TextoInmutable.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Se lanza al intentar alterar un texto ya publicado.
 *
 * Editarlo dejaría sin sentido a todos los consentimientos que lo apuntan: no
 * se podría decir qué leyó la persona al aceptar. Para cambiar el texto se
 * publica una versión nueva.
 */
class TextoInmutable extends DomainException {}
```

- [ ] **Step 5: Modelo**

Crear `src/Privacidad/Modelos/TextoInformativo.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\TextoInmutable;

class TextoInformativo extends Model
{
    protected $table = 'privacidad_textos';

    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
        'vigente_desde' => 'datetime',
        'vigente_hasta' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Inmutable en el contenido. Cerrar la vigencia SÍ es una transición
        // legítima —publicar la versión siguiente cierra la anterior— y por eso
        // Textos::publicar() la hace por query builder, que no dispara eventos.
        static::updating(function (): never {
            throw new TextoInmutable(
                'Un texto publicado no se modifica: los consentimientos que lo apuntan '
                .'dejarían de acreditar qué leyó el titular. Publicar una versión nueva.',
            );
        });

        static::deleting(function (): never {
            throw new TextoInmutable('Un texto publicado no se borra: es la prueba de lo que se informó.');
        });
    }

    /** @param Builder<TextoInformativo> $query */
    public function scopeDelSistema(Builder $query, string $sistema): void
    {
        $query->where('sistema', $sistema);
    }

    /** @param Builder<TextoInformativo> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('vigente_hasta');
    }
}
```

- [ ] **Step 6: Servicio**

Crear `src/Privacidad/Textos.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;

class Textos
{
    public function publicar(string $codigo, string $contenido): TextoInformativo
    {
        return DB::transaction(function () use ($codigo, $contenido): TextoInformativo {
            $sistema = (string) config('privacidad.sistema');

            $anterior = $this->vigente($codigo);

            if ($anterior !== null) {
                // Por query builder: el modelo es inmutable y rechazaría updating.
                TextoInformativo::query()->whereKey($anterior->getKey())
                    ->update(['vigente_hasta' => now()]);
            }

            $ultima = TextoInformativo::query()->delSistema($sistema)
                ->where('codigo', $codigo)->max('version') ?? 0;

            return TextoInformativo::create([
                'sistema' => $sistema,
                'codigo' => $codigo,
                'version' => $ultima + 1,
                'contenido' => $contenido,
                'hash' => hash('sha256', $contenido),
                'vigente_desde' => now(),
            ]);
        });
    }

    public function vigente(string $codigo): ?TextoInformativo
    {
        return TextoInformativo::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('codigo', $codigo)
            ->vigentes()
            ->orderByDesc('version')
            ->first();
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/TextoInformativoTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 8: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): textos informativos versionados e inmutables

Editar un texto publicado dejaría sin sentido a todos los consentimientos que
lo apuntan: no se podría decir qué leyó quien aceptó."
```

---

### Task 2: Registrar que se informó al titular

**Files:**
- Create: `database/migrations/2026_08_14_000112_create_privacidad_informaciones_table.php`
- Create: `src/Privacidad/Modelos/InformacionEntregada.php`
- Create: `src/Privacidad/Informaciones.php`
- Create: `src/Privacidad/TextoNoPublicado.php`
- Test: `tests/Privacidad/InformacionEntregadaTest.php`

**Interfaces:**
- Consumes: `Textos`, `TextoInformativo` (Task 1); `RegistroDeEvidencia` (ciclo 1).
- Produces: `Informaciones::registrar(Model $titular, string $codigo, MedioDeConsentimiento $medio, array $opciones = []): InformacionEntregada`; `Informaciones::seInformo(Model $titular, string $codigo): bool`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/InformacionEntregadaTest.php`:

```php
<?php

use Muni\Shared\Privacidad\Informaciones;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\InformacionEntregada;
use Muni\Shared\Privacidad\TextoNoPublicado;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->texto = app(Textos::class)->publicar('aviso_recoleccion', 'Sus datos se tratan para…');
});

it('sella qué versión exacta vio el titular', function () {
    $registro = app(Informaciones::class)
        ->registrar($this->titular, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel);

    expect($registro->texto_id)->toBe($this->texto->getKey())
        ->and($registro->entregado_en)->not->toBeNull()
        ->and(app(Informaciones::class)->seInformo($this->titular, 'aviso_recoleccion'))->toBeTrue();
});

it('sigue apuntando a la versión vieja aunque después se publique otra', function () {
    app(Informaciones::class)->registrar($this->titular, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel);

    app(Textos::class)->publicar('aviso_recoleccion', 'Texto nuevo');

    // Lo que importa acreditar es qué leyó ELLA, no qué dice el texto de hoy.
    expect(InformacionEntregada::sole()->texto_id)->toBe($this->texto->getKey());
});

it('rechaza informar con un código que nadie publicó', function () {
    expect(fn () => app(Informaciones::class)
        ->registrar($this->titular, 'inexistente', MedioDeConsentimiento::FirmaPapel))
        ->toThrow(TextoNoPublicado::class);
});

it('no deja rastro cuando el texto no existe', function () {
    rescue(fn () => app(Informaciones::class)
        ->registrar($this->titular, 'inexistente', MedioDeConsentimiento::FirmaPapel));

    expect(InformacionEntregada::count())->toBe(0);
});

it('deja constancia en la bitácora sin volcar el contenido del texto', function () {
    app(Informaciones::class)->registrar($this->titular, 'aviso_recoleccion', MedioDeConsentimiento::FirmaPapel);

    $entrada = EntradaBitacora::where('evento', 'informacion.entregada')->sole();

    expect($entrada->datos)->toHaveKey('codigo')
        ->and($entrada->datos)->not->toHaveKey('contenido');
});

it('sabe que no se informó cuando no hay registro', function () {
    expect(app(Informaciones::class)->seInformo($this->titular, 'aviso_recoleccion'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/InformacionEntregadaTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Informaciones" not found`.

- [ ] **Step 3: Migración**

Crear `database/migrations/2026_08_14_000112_create_privacidad_informaciones_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La prueba de que se informó al titular, y de qué versión del texto vio.
 *
 * Es la obligación que más veces se ejerce —ocurre en cada inscripción, no una
 * vez al año como el RAT— y la que no tenía ningún soporte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_informaciones', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->morphs('titular');
            $table->foreignId('texto_id')->constrained('privacidad_textos')->restrictOnDelete();
            $table->timestamp('entregado_en');
            $table->string('medio');
            $table->foreignId('user_id')->nullable();
            $table->string('ip_hash')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_informaciones');
    }
};
```

`restrictOnDelete` a propósito: un texto apuntado por una entrega no se puede borrar ni por cascada.

- [ ] **Step 4: Excepción**

Crear `src/Privacidad/TextoNoPublicado.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Se intentó registrar que se informó al titular con un texto que no existe.
 *
 * Falla en vez de registrar una entrega vacía: un registro que dice "se informó"
 * sin poder decir qué se informó es peor que no tener registro, porque parece
 * cumplimiento.
 */
class TextoNoPublicado extends DomainException {}
```

- [ ] **Step 5: Modelo**

Crear `src/Privacidad/Modelos/InformacionEntregada.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Muni\Shared\Privacidad\MedioDeConsentimiento;

class InformacionEntregada extends Model
{
    protected $table = 'privacidad_informaciones';

    protected $guarded = [];

    protected $casts = [
        'medio' => MedioDeConsentimiento::class,
        'entregado_en' => 'datetime',
    ];

    /** @return MorphTo<Model, InformacionEntregada> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<TextoInformativo, InformacionEntregada> */
    public function texto(): BelongsTo
    {
        return $this->belongsTo(TextoInformativo::class, 'texto_id');
    }
}
```

- [ ] **Step 6: Servicio**

Crear `src/Privacidad/Informaciones.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\InformacionEntregada;

/**
 * El módulo no muestra nada: cada sistema renderiza el texto en su formulario y
 * llama acá para sellar que lo mostró. Lo que el módulo aporta es el texto
 * vigente y la prueba de la entrega.
 */
class Informaciones
{
    public function __construct(
        private readonly Textos $textos,
        private readonly RegistroDeEvidencia $evidencia,
    ) {}

    /** @param array<string, mixed> $opciones */
    public function registrar(
        Model $titular,
        string $codigo,
        MedioDeConsentimiento $medio,
        array $opciones = [],
    ): InformacionEntregada {
        $texto = $this->textos->vigente($codigo);

        if ($texto === null) {
            throw new TextoNoPublicado(
                "No hay un texto vigente con código «{$codigo}» en este sistema: "
                .'no se puede acreditar que se informó algo que no está publicado.',
            );
        }

        return DB::transaction(function () use ($titular, $texto, $codigo, $medio, $opciones): InformacionEntregada {
            $registro = InformacionEntregada::create([
                'sistema' => (string) config('privacidad.sistema'),
                'titular_type' => $titular->getMorphClass(),
                'titular_id' => $titular->getKey(),
                'texto_id' => $texto->getKey(),
                'entregado_en' => now(),
                'medio' => $medio,
                'user_id' => Auth::id(),
                'ip_hash' => isset($opciones['ip']) ? hash('sha256', (string) $opciones['ip']) : null,
            ]);

            // Solo el código y la versión: volcar el contenido duplicaría en la
            // bitácora un texto que ya vive, íntegro y con su hash, en privacidad_textos.
            $this->evidencia->registrar('informacion.entregada', [
                'codigo' => $codigo,
                'version' => $texto->version,
            ], $titular);

            return $registro;
        });
    }

    public function seInformo(Model $titular, string $codigo): bool
    {
        return InformacionEntregada::query()
            ->where('titular_type', $titular->getMorphClass())
            ->where('titular_id', $titular->getKey())
            ->whereHas('texto', fn ($q) => $q->where('codigo', $codigo))
            ->exists();
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/InformacionEntregadaTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 8: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): acreditar que se informó al titular y con qué versión

Un registro que dice «se informó» sin poder decir qué se informó parece
cumplimiento y no lo es."
```

---

### Task 3: El consentimiento apunta al texto exacto

Cierra el hueco 7: hoy `version_texto` es un string suelto que nada obliga a llenar.

**Files:**
- Create: `database/migrations/2026_08_14_000113_consentimiento_apunta_al_texto.php`
- Modify: `src/Privacidad/Modelos/Consentimiento.php`
- Modify: `src/Privacidad/Consentimientos.php`
- Test: `tests/Privacidad/ConsentimientoTextoTest.php`

**Interfaces:**
- Consumes: `Textos`, `TextoInformativo` (Task 1).
- Produces: `privacidad_consentimientos.texto_id`; `Consentimiento::texto()`; `Consentimientos::otorgar()` acepta `$opciones['codigo_texto']`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/ConsentimientoTextoTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Textos;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
    $this->texto = app(Textos::class)->publicar('consentimiento_difusion', 'Autorizo la difusión…');
});

it('guarda a qué texto exacto se dio el consentimiento', function () {
    $consentimiento = app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['codigo_texto' => 'consentimiento_difusion'],
    );

    expect($consentimiento->texto_id)->toBe($this->texto->getKey())
        ->and($consentimiento->texto->contenido)->toBe('Autorizo la difusión…');
});

it('el consentimiento sigue apuntando a la versión vieja tras publicar una nueva', function () {
    app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
        ['codigo_texto' => 'consentimiento_difusion'],
    );

    app(Textos::class)->publicar('consentimiento_difusion', 'Texto distinto');

    expect(Consentimiento::sole()->texto->contenido)->toBe('Autorizo la difusión…');
});

it('sigue permitiendo otorgar sin texto, para los consentimientos en papel previos', function () {
    $consentimiento = app(Consentimientos::class)->otorgar(
        $this->titular, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
    );

    expect($consentimiento->exists)->toBeTrue()
        ->and($consentimiento->texto_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/ConsentimientoTextoTest.php`
Expected: FAIL — columna `texto_id` inexistente.

- [ ] **Step 3: Migración**

Crear `database/migrations/2026_08_14_000113_consentimiento_apunta_al_texto.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `version_texto` era un string suelto que nada obligaba a llenar: no servía
 * para acreditar nada. `texto_id` apunta a la fila exacta, con su hash.
 *
 * Los consentimientos que ya existen quedan con `texto_id` null a propósito:
 * inventarles retroactivamente un texto sería falsear justo la prueba que la
 * ley pide. La columna vieja se conserva por si guardaba algo interpretable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            $table->foreignId('texto_id')->nullable()->after('medio')
                ->constrained('privacidad_textos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_consentimientos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('texto_id');
        });
    }
};
```

- [ ] **Step 4: Relación en el modelo**

En `src/Privacidad/Modelos/Consentimiento.php`, agregar:

```php
    /** @return BelongsTo<TextoInformativo, Consentimiento> */
    public function texto(): BelongsTo
    {
        return $this->belongsTo(TextoInformativo::class, 'texto_id');
    }
```

- [ ] **Step 5: Resolver el texto al otorgar**

En `src/Privacidad/Consentimientos.php`, inyectar `Textos` en el constructor junto al `RegistroDeEvidencia` existente, y dentro del `create([...])` agregar:

```php
                'texto_id' => isset($opciones['codigo_texto'])
                    ? $this->textos->vigente((string) $opciones['codigo_texto'])?->getKey()
                    : null,
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/ConsentimientoTextoTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): el consentimiento apunta al texto exacto que se aceptó

version_texto era un string suelto que nada obligaba a llenar. Los
consentimientos previos quedan sin texto a propósito: inventárselo sería
falsear la prueba."
```

---

### Task 4: Régimen reforzado de NNA

**Files:**
- Modify: `src/Privacidad/Contratos/TitularDeDatos.php`
- Modify: `tests/Privacidad/Fixtures/PersonaDePrueba.php`
- Create: `src/Privacidad/Edades.php`
- Create: `src/Privacidad/EdadNoAcreditada.php`
- Create: `database/migrations/2026_08_14_000114_add_admite_nna_a_finalidades.php`
- Modify: `src/Privacidad/Modelos/Finalidad.php`
- Modify: `src/Privacidad/Consentimientos.php`
- Test: `tests/Privacidad/NnaTest.php`

**Interfaces:**
- Consumes: `TitularDeDatos`, `Consentimientos`, `Finalidad`.
- Produces: `TitularDeDatos::fechaNacimientoTitular(): ?DateTimeInterface` (**séptimo método**); `Edades::esNNA(TitularDeDatos): ?bool`; `Finalidad::$admite_nna`; `EdadNoAcreditada extends \DomainException`.

> **Este es el cambio de contrato.** Hoy solo `discapacidad-graneros` implementa
> `TitularDeDatos`, y en una rama sin mergear. Después de adoptar el módulo en
> los otros siete sistemas, este mismo cambio cuesta ocho veces más.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/NnaTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\Edades;
use Muni\Shared\Privacidad\EdadNoAcreditada;
use Muni\Shared\Privacidad\FinalidadInvalida;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'difusion', 'nombre' => 'Difusión',
        'base_licitud' => BaseLicitud::Consentimiento, 'es_accesoria' => true,
    ]);
});

it('reconoce a un menor de edad', function () {
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor', 'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    expect(app(Edades::class)->esNNA($nna))->toBeTrue();
});

it('reconoce a un adulto', function () {
    $adulto = PersonaDePrueba::create([
        'nombre' => 'Adulta', 'documento' => '22.222.222-2',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    expect(app(Edades::class)->esNNA($adulto))->toBeFalse();
});

it('sin fecha de nacimiento devuelve null, que NO es adulto', function () {
    $sinFecha = PersonaDePrueba::create(['nombre' => 'Sin fecha', 'documento' => '33.333.333-3']);

    expect(app(Edades::class)->esNNA($sinFecha))->toBeNull();
});

it('el consentimiento de un NNA exige que lo otorgue el tutor', function () {
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor', 'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $nna, $this->finalidad, MedioDeConsentimiento::FirmaPapel, ['otorgado_por' => 'titular'],
    ))->toThrow(EdadNoAcreditada::class);

    $ok = app(Consentimientos::class)->otorgar(
        $nna, $this->finalidad, MedioDeConsentimiento::FirmaPapel, ['otorgado_por' => 'tutor'],
    );

    expect($ok->exists)->toBeTrue();
});

it('rechaza pedir consentimiento a quien no tiene la edad acreditada', function () {
    $sinFecha = PersonaDePrueba::create(['nombre' => 'Sin fecha', 'documento' => '33.333.333-3']);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $sinFecha, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
    ))->toThrow(EdadNoAcreditada::class);
});

it('una finalidad que no admite NNA los rechaza aunque consienta el tutor', function () {
    $this->finalidad->update(['admite_nna' => false]);
    $nna = PersonaDePrueba::create([
        'nombre' => 'Menor', 'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(10)->toDateString(),
    ]);

    expect(fn () => app(Consentimientos::class)->otorgar(
        $nna, $this->finalidad, MedioDeConsentimiento::FirmaPapel, ['otorgado_por' => 'tutor'],
    ))->toThrow(FinalidadInvalida::class);
});

it('el adulto sigue otorgando su consentimiento sin fricción', function () {
    $adulto = PersonaDePrueba::create([
        'nombre' => 'Adulta', 'documento' => '22.222.222-2',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    expect(app(Consentimientos::class)->otorgar(
        $adulto, $this->finalidad, MedioDeConsentimiento::FirmaPapel,
    )->exists)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/NnaTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Edades" not found`.

- [ ] **Step 3: Séptimo método del contrato**

En `src/Privacidad/Contratos/TitularDeDatos.php`, agregar:

```php
    /**
     * Fecha de nacimiento del titular, o null si el sistema no la tiene acreditada.
     *
     * `null` NO significa adulto: significa que la edad no está acreditada. La
     * ley da régimen reforzado a los NNA, y un registro que asume mayoría de
     * edad por omisión trata a un menor como si pudiera consentir solo.
     *
     * Quién es NNA lo decide el módulo (`Edades`), no cada sistema: es una regla
     * legal, y reimplementada ocho veces se equivoca ocho veces.
     */
    public function fechaNacimientoTitular(): ?\DateTimeInterface;
```

Implementarlo en `tests/Privacidad/Fixtures/PersonaDePrueba.php`:

```php
    public function fechaNacimientoTitular(): ?\DateTimeInterface
    {
        return $this->fecha_nacimiento ? \Illuminate\Support\Carbon::parse($this->fecha_nacimiento) : null;
    }
```

Y agregar `fecha_nacimiento` (date, nullable) a la migración del fixture en `tests/Privacidad/Fixtures/migrations/`.

- [ ] **Step 4: Excepción y servicio de edades**

Crear `src/Privacidad/EdadNoAcreditada.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * El tratamiento exige saber si el titular es NNA y no se sabe, o se pidió su
 * consentimiento directo siendo menor.
 *
 * Falla en vez de asumir mayoría de edad: un registro comunal de discapacidad
 * tiene menores con certeza, y asumir que pueden consentir solos es el error caro.
 */
class EdadNoAcreditada extends DomainException {}
```

Crear `src/Privacidad/Edades.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

class Edades
{
    /** Mayoría de edad en Chile. Vive acá y no en ocho sistemas. */
    public const MAYORIA_DE_EDAD = 18;

    /** null = edad no acreditada. No es lo mismo que adulto. */
    public function esNNA(TitularDeDatos $titular): ?bool
    {
        $nacimiento = $titular->fechaNacimientoTitular();

        if ($nacimiento === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::instance($nacimiento)->age < self::MAYORIA_DE_EDAD;
    }
}
```

- [ ] **Step 5: `admite_nna` en las finalidades**

Crear `database/migrations/2026_08_14_000114_add_admite_nna_a_finalidades.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_finalidades', function (Blueprint $table): void {
            // Default true: las finalidades existentes ya tratan menores y
            // apagarlas retroactivamente rompería el registro comunal.
            $table->boolean('admite_nna')->default(true)->after('es_accesoria');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_finalidades', function (Blueprint $table): void {
            $table->dropColumn('admite_nna');
        });
    }
};
```

Agregar `'admite_nna' => 'boolean'` al `$casts` de `Finalidad`.

- [ ] **Step 6: Aplicarlo al consentimiento**

En `src/Privacidad/Consentimientos.php`, inyectar `Edades` y, dentro de `otorgar()` **antes** de la transacción:

```php
        if ($titular instanceof TitularDeDatos) {
            $esNNA = $this->edades->esNNA($titular);

            if ($esNNA === null) {
                throw new EdadNoAcreditada(
                    'No se puede pedir consentimiento sin saber si el titular es mayor de edad: '
                    .'la edad no está acreditada en este sistema.',
                );
            }

            if ($esNNA && ! $finalidad->admite_nna) {
                throw new FinalidadInvalida(
                    "La finalidad «{$finalidad->codigo}» no admite el tratamiento de menores de edad.",
                );
            }

            if ($esNNA && ($opciones['otorgado_por'] ?? 'titular') !== 'tutor') {
                throw new EdadNoAcreditada(
                    'El consentimiento de un menor de edad lo otorga su representante, no él mismo.',
                );
            }
        }
```

La comprobación es condicional a `instanceof TitularDeDatos` para no romper a quien pase un `Model` cualquiera, que es lo que la firma admite hoy.

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/NnaTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 8: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): régimen reforzado de niños, niñas y adolescentes

Fecha de nacimiento desconocida no es adulto: es un estado propio que hay que
resolver. Un registro comunal de discapacidad tiene menores con certeza."
```

---

## Notas para quien ejecute

- **La Task 4 cambia el contrato `TitularDeDatos`.** Al terminar, `discapacidad-graneros` deja de compilar hasta que implemente el séptimo método. Eso es esperado y es el motivo de hacerlo ahora: hoy es un repo, después son ocho.
- Los textos y el consentimiento son inmutables por diseño. Si un test necesita cambiar uno, publica una versión nueva; no relajes el candado.
- `Textos::publicar()` cierra la versión anterior por query builder a propósito, igual que `Bitacora::desvincular()` en el Plan A.
- El servicio `Informaciones` **no renderiza nada**. Si aparece la tentación de meter vistas o blade acá, es señal de que la responsabilidad se está corriendo al lugar equivocado.
