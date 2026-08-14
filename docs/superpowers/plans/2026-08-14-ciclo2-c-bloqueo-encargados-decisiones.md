# Ciclo 2 — Plan C: bloqueo, encargados y decisiones automatizadas

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar los tres huecos restantes del ciclo 2: suspender el tratamiento en disputa, registrar a los encargados y sus contratos, y declarar las decisiones automatizadas.

**Architecture:** Tres piezas independientes entre sí. Ninguna cambia el contrato `TitularDeDatos`, así que este plan puede ejecutarse antes o después del Plan B sin conflicto.

**Tech Stack:** PHP 8.3+, Laravel 11/12/13, Pest 3/4, Orchestra Testbench, SQLite en tests.

**Spec:** `docs/superpowers/specs/2026-08-14-ley-21719-ciclo2-design.md` (huecos 4, 5 y 8)
**Depende de:** Plan A terminado.

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

- `TipoDeSolicitud`: `acceso`, `rectificacion`, `supresion`, `oposicion`, `portabilidad`.
- `EstadoDeSolicitud`: `recibida`, `en_tramite`, `acogida`, `acogida_parcial`, `rechazada`, más `estaResuelta(): bool`.
- `Solicitudes` expone `registrar()`, `tomar()`, `acoger()`, `acogerParcialmente()`, `rechazar()`, y su constructor recibe solo `RegistroDeEvidencia`.
- `Finalidad` tiene `destinatarios` como json.

## Migraciones ya existentes (no reordenar)

`2026_08_13_00000{1..6}`, `2026_08_14_000001`, `2026_08_14_000002`, y las `2026_08_14_0001{11..14}` del Plan B si ya corrió. Las de este plan van `2026_08_14_0002{1,2,3}`.

---

### Task 1: Bloqueo del tratamiento en disputa

Hoy, mientras una rectificación está pendiente, **el dato incorrecto se sigue usando**. `TipoDeSolicitud::Oposicion` existe como tipo pero resolverla no hace nada.

**Files:**
- Create: `database/migrations/2026_08_14_000211_create_privacidad_bloqueos_table.php`
- Create: `src/Privacidad/Modelos/Bloqueo.php`
- Create: `src/Privacidad/Bloqueos.php`
- Modify: `config/privacidad.php`
- Modify: `src/Privacidad/Solicitudes.php`
- Test: `tests/Privacidad/BloqueoTest.php`

**Interfaces:**
- Consumes: `Solicitud`, `TipoDeSolicitud`, `RegistroDeEvidencia`.
- Produces: `Bloqueos::bloquear(Model $titular, ?Finalidad $finalidad, string $motivo, ?Solicitud $solicitud = null): Bloqueo`; `Bloqueos::levantarPorSolicitud(Solicitud $solicitud): int`; `Bloqueos::vigente(Model $titular, ?Finalidad $finalidad = null): bool`; config `privacidad.bloquear_durante_solicitud`.

> **Lo que este módulo NO puede hacer.** Puede registrar que un tratamiento está
> bloqueado y ofrecer la consulta, pero no puede impedir que un sistema la ignore.
> Se dice explícitamente en vez de fingir una garantía: el módulo aporta el
> estado, la consulta y el helper de test; que el sistema lo respete es
> obligación de la adopción y va como punto verificable del plan de adopción.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/BloqueoTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Bloqueos;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.bloquear_durante_solicitud' => true]);
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
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

it('con la configuración apagada no bloquea nada', function () {
    config(['privacidad.bloquear_durante_solicitud' => false]);

    app(Solicitudes::class)->registrar(
        $this->titular, TipoDeSolicitud::Rectificacion, 'Mi apellido está mal', $this->verificacion,
    );

    expect(app(Bloqueos::class)->vigente($this->titular))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/BloqueoTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Bloqueos" not found`.

- [ ] **Step 3: Migración**

Crear `database/migrations/2026_08_14_000211_create_privacidad_bloqueos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspensión del tratamiento mientras hay una disputa abierta.
 *
 * Sin esto, una rectificación pendiente no impide que el dato incorrecto se
 * siga usando: el sistema sigue operando con lo que el titular ya dijo que
 * está mal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_bloqueos', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->morphs('titular');
            // Null = todas las finalidades.
            $table->foreignId('finalidad_id')->nullable()->constrained('privacidad_finalidades')->cascadeOnDelete();
            $table->foreignId('solicitud_id')->nullable()->constrained('privacidad_solicitudes')->nullOnDelete();
            $table->text('motivo');
            $table->timestamp('desde');
            // Levantar no borra: la suspensión pasada es un hecho acreditable.
            $table->timestamp('levantado_en')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index(['titular_type', 'titular_id', 'levantado_en'], 'privacidad_bloqueos_titular_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_bloqueos');
    }
};
```

- [ ] **Step 4: Modelo**

Crear `src/Privacidad/Modelos/Bloqueo.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Bloqueo extends Model
{
    protected $table = 'privacidad_bloqueos';

    protected $guarded = [];

    protected $casts = [
        'desde' => 'datetime',
        'levantado_en' => 'datetime',
    ];

    /** @return MorphTo<Model, Bloqueo> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Finalidad, Bloqueo> */
    public function finalidad(): BelongsTo
    {
        return $this->belongsTo(Finalidad::class, 'finalidad_id');
    }

    /** @param Builder<Bloqueo> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('levantado_en');
    }
}
```

- [ ] **Step 5: Servicio**

Crear `src/Privacidad/Bloqueos.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Bloqueo;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * El módulo REGISTRA el bloqueo y ofrece la consulta; no puede impedir que un
 * sistema la ignore. Eso se dice acá y no se disfraza: quien adopte el módulo
 * tiene que consultar `vigente()` antes de tratar, y verificarlo es punto
 * obligatorio del plan de adopción.
 */
class Bloqueos
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    public function bloquear(
        Model $titular,
        ?Finalidad $finalidad,
        string $motivo,
        ?Solicitud $solicitud = null,
    ): Bloqueo {
        return DB::transaction(function () use ($titular, $finalidad, $motivo, $solicitud): Bloqueo {
            $bloqueo = Bloqueo::create([
                'sistema' => (string) config('privacidad.sistema'),
                'titular_type' => $titular->getMorphClass(),
                'titular_id' => $titular->getKey(),
                'finalidad_id' => $finalidad?->getKey(),
                'solicitud_id' => $solicitud?->getKey(),
                'motivo' => $motivo,
                'desde' => now(),
                'user_id' => Auth::id(),
            ]);

            $this->evidencia->registrar('bloqueo.aplicado', [
                'finalidad' => $finalidad?->codigo,
                'solicitud_id' => $solicitud?->getKey(),
            ], $titular);

            return $bloqueo;
        });
    }

    /** @return int cuántos bloqueos quedaron levantados */
    public function levantarPorSolicitud(Solicitud $solicitud): int
    {
        return DB::transaction(function () use ($solicitud): int {
            $afectados = Bloqueo::query()
                ->where('solicitud_id', $solicitud->getKey())
                ->vigentes()
                ->update(['levantado_en' => now()]);

            if ($afectados > 0) {
                $this->evidencia->registrar('bloqueo.levantado', [
                    'solicitud_id' => $solicitud->getKey(),
                    'bloqueos' => $afectados,
                ], $solicitud->titular);
            }

            return $afectados;
        });
    }

    public function vigente(Model $titular, ?Finalidad $finalidad = null): bool
    {
        return Bloqueo::query()
            ->where('titular_type', $titular->getMorphClass())
            ->where('titular_id', $titular->getKey())
            ->vigentes()
            // Un bloqueo sin finalidad alcanza a todas.
            ->where(fn ($q) => $q->whereNull('finalidad_id')
                ->when($finalidad, fn ($q) => $q->orWhere('finalidad_id', $finalidad->getKey())))
            ->exists();
    }
}
```

- [ ] **Step 6: Configuración**

En `config/privacidad.php`:

```php
    // Registrar una rectificación u oposición suspende el tratamiento hasta
    // resolverla. Configurable porque frena la operación del mesón: revisarlo
    // con la jefatura antes de producción.
    'bloquear_durante_solicitud' => (bool) env('PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD', true),
```

- [ ] **Step 7: Enganchar con las solicitudes**

En `src/Privacidad/Solicitudes.php`, inyectar `Bloqueos` en el constructor (junto al `RegistroDeEvidencia` existente).

Dentro de `registrar()`, **dentro** de la transacción y después de crear la solicitud:

```php
            // Solo rectificación y oposición: un acceso o una portabilidad no
            // ponen nada en disputa, y bloquear por ellas frenaría la atención
            // sin ninguna razón legal.
            $disputa = in_array($tipo, [TipoDeSolicitud::Rectificacion, TipoDeSolicitud::Oposicion], true);

            if ($disputa && config('privacidad.bloquear_durante_solicitud')) {
                $this->bloqueos->bloquear($titular, null, "Solicitud de {$tipo->etiqueta()} en trámite", $solicitud);
            }
```

Y en el `resolver()` privado, después del `update([...])` y dentro de la misma transacción:

```php
            $this->bloqueos->levantarPorSolicitud($solicitud);
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/BloqueoTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 9: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad config/privacidad.php database/migrations tests/Privacidad
git commit -m "feat(privacidad): suspender el tratamiento mientras la solicitud está en disputa

Sin esto, una rectificación pendiente no impedía seguir usando el dato que el
titular ya dijo que está mal."
```

---

### Task 2: Encargados de tratamiento y sus contratos

`destinatarios` es una lista json en el RAT. Pero comunicar datos a un tercero exige contrato y registro de la cesión, y eso no existe.

**Files:**
- Create: `database/migrations/2026_08_14_000212_create_privacidad_encargados_tables.php`
- Create: `src/Privacidad/Modelos/Encargado.php`
- Modify: `src/Privacidad/Modelos/Finalidad.php`
- Modify: `src/Privacidad/Console/ExportarRatCommand.php`
- Test: `tests/Privacidad/EncargadoTest.php`

**Interfaces:**
- Consumes: `Finalidad`.
- Produces: `Encargado` (tabla `privacidad_encargados`); pivote `privacidad_encargado_finalidad`; `Finalidad::encargados()`; `Encargado::scopeSinContratoVigente()`; el RAT exporta encargados y avisa de los que no tienen contrato al día.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/EncargadoTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\Encargado;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.responsable.nombre' => 'I. Municipalidad de Graneros']);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'registro_comunal', 'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);
});

it('asocia un encargado a una finalidad', function () {
    $encargado = Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Maestro de personas', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subMonth(), 'contrato_vence_en' => now()->addYear(),
    ]);

    $this->finalidad->encargados()->attach($encargado);

    expect($this->finalidad->fresh()->encargados)->toHaveCount(1)
        ->and($this->finalidad->fresh()->encargados->first()->nombre)->toBe('Maestro de personas');
});

it('detecta a los encargados sin contrato firmado', function () {
    Encargado::create(['sistema' => 'discapacidad', 'nombre' => 'Sin contrato', 'rol' => 'encargado']);

    expect(Encargado::sinContratoVigente()->pluck('nombre')->all())->toBe(['Sin contrato']);
});

it('detecta a los encargados con contrato vencido', function () {
    Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Vencido', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subYears(3), 'contrato_vence_en' => now()->subMonth(),
    ]);

    expect(Encargado::sinContratoVigente()->pluck('nombre')->all())->toBe(['Vencido']);
});

it('no marca al que tiene contrato al día', function () {
    Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Al día', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subMonth(), 'contrato_vence_en' => now()->addYear(),
    ]);

    expect(Encargado::sinContratoVigente()->count())->toBe(0);
});

it('un contrato sin fecha de vencimiento se considera vigente', function () {
    Encargado::create([
        'sistema' => 'discapacidad', 'nombre' => 'Indefinido', 'rol' => 'encargado',
        'contrato_firmado_en' => now()->subMonth(),
    ]);

    expect(Encargado::sinContratoVigente()->count())->toBe(0);
});

it('el RAT avisa de los encargados sin contrato al día', function () {
    Encargado::create(['sistema' => 'discapacidad', 'nombre' => 'Sin contrato', 'rol' => 'encargado']);

    $this->artisan('privacidad:rat')
        ->expectsOutputToContain('sin contrato al día')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/EncargadoTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Modelos\Encargado" not found`.

- [ ] **Step 3: Migración**

Crear `database/migrations/2026_08_14_000212_create_privacidad_encargados_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién más toca estos datos, y con qué contrato.
 *
 * `destinatarios` en las finalidades era una lista json descriptiva. La ley
 * exige contrato con cada encargado y registro de las cesiones: una lista de
 * nombres no acredita ninguna de las dos cosas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_encargados', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->string('nombre');
            $table->string('rol')->default('encargado'); // encargado | destinatario
            $table->string('contrato_path')->nullable();
            $table->date('contrato_firmado_en')->nullable();
            $table->date('contrato_vence_en')->nullable();
            $table->string('pais')->nullable();
            $table->text('medidas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['sistema', 'activo']);
        });

        Schema::create('privacidad_encargado_finalidad', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encargado_id')->constrained('privacidad_encargados')->cascadeOnDelete();
            $table->foreignId('finalidad_id')->constrained('privacidad_finalidades')->cascadeOnDelete();

            $table->unique(['encargado_id', 'finalidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_encargado_finalidad');
        Schema::dropIfExists('privacidad_encargados');
    }
};
```

- [ ] **Step 4: Modelo**

Crear `src/Privacidad/Modelos/Encargado.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Encargado extends Model
{
    protected $table = 'privacidad_encargados';

    protected $guarded = [];

    protected $casts = [
        'contrato_firmado_en' => 'date',
        'contrato_vence_en' => 'date',
        'activo' => 'boolean',
    ];

    /** @return BelongsToMany<Finalidad> */
    public function finalidades(): BelongsToMany
    {
        return $this->belongsToMany(Finalidad::class, 'privacidad_encargado_finalidad', 'encargado_id', 'finalidad_id');
    }

    /**
     * Sin contrato firmado, o con uno ya vencido.
     *
     * Un vencimiento nulo se toma como indefinido, no como vencido: hay
     * convenios municipales sin plazo y marcarlos en rojo sería ruido.
     *
     * @param Builder<Encargado> $query
     */
    public function scopeSinContratoVigente(Builder $query): void
    {
        $query->where('activo', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('contrato_firmado_en')
                ->orWhere(fn (Builder $q) => $q
                    ->whereNotNull('contrato_vence_en')
                    ->where('contrato_vence_en', '<', now()->toDateString())));
    }
}
```

En `src/Privacidad/Modelos/Finalidad.php` agregar la inversa:

```php
    /** @return BelongsToMany<Encargado> */
    public function encargados(): BelongsToMany
    {
        return $this->belongsToMany(Encargado::class, 'privacidad_encargado_finalidad', 'finalidad_id', 'encargado_id');
    }
```

- [ ] **Step 5: El RAT los expone y avisa**

En `src/Privacidad/Console/ExportarRatCommand.php`:

- Al mapeo del JSON de cada finalidad, agregar
  `'encargados' => $f->encargados->map(fn (Encargado $e) => ['nombre' => $e->nombre, 'rol' => $e->rol, 'contrato_vence_en' => $e->contrato_vence_en?->toDateString()])->all(),`
  (cargar con `->with('encargados')` en la consulta para no hacer N+1).
- Después de la tabla, en el camino no-JSON:

```php
        $sinContrato = Encargado::query()->where('sistema', $sistema)->sinContratoVigente()->pluck('nombre');

        if ($sinContrato->isNotEmpty()) {
            $this->warn('Encargados sin contrato al día: '.$sinContrato->implode(', ')
                .'. La ley exige contrato con cada encargado del tratamiento.');
        }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/EncargadoTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 7: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): registrar a los encargados y el estado de sus contratos

Una lista de nombres en json no acredita ni el contrato ni la cesión."
```

---

### Task 3: Registro de decisiones automatizadas

**Antes de implementar, hay que averiguar si existen.** Los candidatos en el
ecosistema son el OCR de documentos y cualquier priorización automática de
atenciones. Si no hay ninguna, la tabla se crea vacía y el RAT lo declara
explícitamente — que ante una fiscalización también es una respuesta.

**Files:**
- Create: `database/migrations/2026_08_14_000213_create_privacidad_decisiones_automatizadas_table.php`
- Create: `src/Privacidad/Modelos/DecisionAutomatizada.php`
- Modify: `src/Privacidad/Console/ExportarRatCommand.php`
- Test: `tests/Privacidad/DecisionAutomatizadaTest.php`

**Interfaces:**
- Consumes: `Finalidad`.
- Produces: `DecisionAutomatizada`; el RAT las exporta y declara explícitamente cuando no hay ninguna.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/DecisionAutomatizadaTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\DecisionAutomatizada;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'registro_comunal', 'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);
});

it('registra una decisión automatizada con su lógica y consecuencias', function () {
    $decision = DecisionAutomatizada::create([
        'sistema' => 'discapacidad',
        'finalidad_id' => $this->finalidad->getKey(),
        'descripcion' => 'Priorización automática de la lista de espera',
        'logica' => 'Ordena por antigüedad de la solicitud y grado de discapacidad declarado.',
        'consecuencias' => 'Afecta el orden de atención, no el derecho a ser atendido.',
        'permite_revision_humana' => true,
    ]);

    expect($decision->permite_revision_humana)->toBeTrue();
});

it('el RAT en json las expone', function () {
    DecisionAutomatizada::create([
        'sistema' => 'discapacidad', 'finalidad_id' => $this->finalidad->getKey(),
        'descripcion' => 'Priorización de lista de espera', 'logica' => 'Antigüedad y grado.',
        'consecuencias' => 'Orden de atención.', 'permite_revision_humana' => true,
    ]);

    $this->artisan('privacidad:rat --json')->assertSuccessful();
    $rat = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['decisiones_automatizadas'])->toHaveCount(1)
        ->and($rat['decisiones_automatizadas'][0]['descripcion'])->toBe('Priorización de lista de espera');
});

it('declara explícitamente cuando el sistema no toma ninguna', function () {
    $this->artisan('privacidad:rat --json')->assertSuccessful();
    $rat = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    // Declarar que no hay es una respuesta, no un vacío.
    expect($rat['decisiones_automatizadas'])->toBe([]);
});

it('avisa en la tabla cuando una decisión no admite revisión humana', function () {
    DecisionAutomatizada::create([
        'sistema' => 'discapacidad', 'finalidad_id' => $this->finalidad->getKey(),
        'descripcion' => 'Rechazo automático', 'logica' => 'Regla dura.',
        'consecuencias' => 'Deniega el beneficio.', 'permite_revision_humana' => false,
    ]);

    $this->artisan('privacidad:rat')
        ->expectsOutputToContain('sin revisión humana')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/DecisionAutomatizadaTest.php`
Expected: FAIL — clase no encontrada.

- [ ] **Step 3: Migración**

Crear `database/migrations/2026_08_14_000213_create_privacidad_decisiones_automatizadas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro declarativo, no un motor.
 *
 * La ley da derecho a no ser objeto de decisiones automatizadas con efectos
 * significativos. Su valor acá es obligar a la pregunta y poder responderla:
 * declarar que no hay ninguna también es una respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_decisiones_automatizadas', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->foreignId('finalidad_id')->nullable()->constrained('privacidad_finalidades')->nullOnDelete();
            $table->text('descripcion');
            $table->text('logica');
            $table->text('consecuencias');
            $table->boolean('permite_revision_humana')->default(true);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['sistema', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_decisiones_automatizadas');
    }
};
```

- [ ] **Step 4: Modelo**

Crear `src/Privacidad/Modelos/DecisionAutomatizada.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionAutomatizada extends Model
{
    protected $table = 'privacidad_decisiones_automatizadas';

    protected $guarded = [];

    protected $casts = [
        'permite_revision_humana' => 'boolean',
        'activa' => 'boolean',
    ];

    /** @return BelongsTo<Finalidad, DecisionAutomatizada> */
    public function finalidad(): BelongsTo
    {
        return $this->belongsTo(Finalidad::class, 'finalidad_id');
    }

    /** @param Builder<DecisionAutomatizada> $query */
    public function scopeDelSistema(Builder $query, string $sistema): void
    {
        $query->where('sistema', $sistema)->where('activa', true);
    }
}
```

- [ ] **Step 5: El RAT las incluye**

En `src/Privacidad/Console/ExportarRatCommand.php`, agregar al envelope JSON (tanto en el caso con finalidades como en el vacío):

```php
            'decisiones_automatizadas' => DecisionAutomatizada::delSistema($sistema)->get()
                ->map(fn (DecisionAutomatizada $d): array => [
                    'descripcion' => $d->descripcion,
                    'logica' => $d->logica,
                    'consecuencias' => $d->consecuencias,
                    'permite_revision_humana' => $d->permite_revision_humana,
                    'finalidad' => $d->finalidad?->codigo,
                ])->all(),
```

Y en el camino no-JSON, después de la tabla:

```php
        $sinRevision = DecisionAutomatizada::delSistema($sistema)
            ->where('permite_revision_humana', false)->pluck('descripcion');

        if ($sinRevision->isNotEmpty()) {
            $this->warn('Decisiones automatizadas sin revisión humana: '.$sinRevision->implode(', ')
                .'. El titular tiene derecho a pedir intervención humana.');
        }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/DecisionAutomatizadaTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): declarar las decisiones automatizadas del sistema

Declarar que no hay ninguna también es una respuesta ante una fiscalización."
```

---

## Notas para quien ejecute

- **El bloqueo no se puede imponer desde acá.** El módulo registra el estado y ofrece `Bloqueos::vigente()`. Que el sistema lo consulte antes de tratar es obligación de la adopción. No intentar interceptar consultas ni agregar global scopes mágicos: sería una garantía falsa y este módulo ya tuvo una.
- `bloquear_durante_solicitud` viene en `true`. Frena la operación del mesón mientras hay una rectificación en trámite, y eso hay que conversarlo con la jefatura antes de producción. No cambiarlo por decisión técnica.
- La Task 3 empieza por **averiguar** si el sistema toma decisiones automatizadas. Si la respuesta es que no, se implementa igual y el RAT lo declara: la tabla vacía es el entregable.
