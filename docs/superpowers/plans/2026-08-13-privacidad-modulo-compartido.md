# Módulo Privacidad (Ley 21.719) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar en `laravel-muni-shared` un módulo `Privacidad` instalable que cubra RAT, base de licitud, derechos ARCOP con control de plazo, retención con supresión efectiva y registro de brechas.

**Architecture:** Módulo de dominio puro dentro del paquete compartido, sin dependencia de Filament. Las migraciones se cargan solas (`loadMigrationsFrom`) para que actualizar el paquete propague el esquema a los 8 sistemas sin un paso de publicación por repo. Tres contratos (`TitularDeDatos`, `VerificadorIdentidad`, `RegistroDeEvidencia`) son las costuras que permiten un único flujo para sistemas que verifican identidad de formas distintas.

**Tech Stack:** PHP 8.3+, Laravel 13, Pest 3/4, Orchestra Testbench, SQLite en memoria para pruebas.

**Spec:** `docs/superpowers/specs/2026-08-13-ley-21719-design.md`

## Global Constraints

- Namespace raíz del módulo: `Muni\Shared\Privacidad\`.
- Todas las tablas llevan prefijo `privacidad_`.
- El paquete **no** puede depender de `filament/filament` ni de `spatie/laravel-activitylog`. `web-graneros` no tiene panel y los sistemas no comparten versión de activitylog.
- Compatibilidad declarada: `illuminate/* ^11.0|^12.0|^13.0`, `php ^8.3`. No usar sintaxis ni APIs posteriores.
- Textos de dominio, nombres de tabla, columnas, comandos y mensajes en español. Comentarios explican el *porqué*, no el qué.
- Todo job que salga a la red implementa `ShouldQueue`.
- Tests con Pest en español (`it('...')`), estilo `expect()->toBe()`, siguiendo `tests/MaestroPersonaServiceTest.php`.
- Cada tarea termina con `vendor/bin/pest` y `vendor/bin/pint --test` en verde antes del commit.
- Commits en español, sin atribución a IA.

---

### Task 1: Finalidades — el RAT en datos

Monta además la infraestructura de pruebas con base de datos, que hoy no existe en el paquete.

**Files:**
- Create: `src/Privacidad/BaseLicitud.php`
- Create: `src/Privacidad/FinalidadInvalida.php`
- Create: `src/Privacidad/Modelos/Finalidad.php`
- Create: `database/migrations/2026_08_13_000001_create_privacidad_finalidades_table.php`
- Create: `config/privacidad.php`
- Modify: `src/MuniSharedServiceProvider.php`
- Modify: `tests/TestCase.php`
- Test: `tests/Privacidad/FinalidadTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `Muni\Shared\Privacidad\BaseLicitud` (enum string), `Muni\Shared\Privacidad\Modelos\Finalidad` (Eloquent, tabla `privacidad_finalidades`), `Muni\Shared\Privacidad\FinalidadInvalida extends \DomainException`, config `privacidad.*`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/FinalidadTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\FinalidadInvalida;
use Muni\Shared\Privacidad\Modelos\Finalidad;

it('guarda una finalidad fundada en función legal con su norma habilitante', function () {
    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal de personas con discapacidad',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422, art. 1',
        'es_accesoria' => false,
        'categorias_datos' => ['identificacion', 'salud'],
        'destinatarios' => ['maestro_personas'],
    ]);

    expect($finalidad->exists)->toBeTrue()
        ->and($finalidad->base_licitud)->toBe(BaseLicitud::FuncionLegal)
        ->and($finalidad->categorias_datos)->toBe(['identificacion', 'salud']);
});

it('rechaza una finalidad de función legal sin norma habilitante', function () {
    expect(fn () => Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'sin_norma',
        'nombre' => 'Sin norma',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'es_accesoria' => false,
    ]))->toThrow(FinalidadInvalida::class);
});

it('rechaza una finalidad accesoria que no se funde en el consentimiento', function () {
    expect(fn () => Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión en redes',
        'base_licitud' => BaseLicitud::InteresLegitimo,
        'es_accesoria' => true,
    ]))->toThrow(FinalidadInvalida::class);
});

it('sabe qué finalidades exigen consentimiento del titular', function () {
    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'es_accesoria' => false,
    ]);
    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión en redes',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
    ]);

    expect(Finalidad::accesorias()->pluck('codigo')->all())->toBe(['difusion']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/FinalidadTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Modelos\Finalidad" not found`.

- [ ] **Step 3: Configurar la base de datos de pruebas**

Modificar `tests/TestCase.php`, agregando el trait de refresco y el entorno SQLite:

```php
<?php

namespace Muni\Shared\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Muni\Shared\MuniSharedServiceProvider;
use Orchestra\Testbench\TestCase as Base;

/**
 * Base de las pruebas del paquete.
 *
 * Registra el service provider, que es lo que hace que las pruebas ejerciten lo
 * mismo que un sistema real: el transporte de correo, los comandos y las
 * migraciones del módulo de privacidad existen porque el provider los registra,
 * no porque la prueba los arme a mano.
 */
abstract class TestCase extends Base
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MuniSharedServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
```

- [ ] **Step 4: Crear el enum de bases de licitud**

Crear `src/Privacidad/BaseLicitud.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

/**
 * Las bases de licitud del art. 12 de la Ley 21.719.
 *
 * Es un enum y no un string libre porque la base de licitud es lo primero que
 * pregunta una fiscalización, y un valor inventado equivale a no tener base.
 */
enum BaseLicitud: string
{
    case FuncionLegal = 'funcion_legal';
    case Consentimiento = 'consentimiento';
    case Contrato = 'contrato';
    case ObligacionLegal = 'obligacion_legal';
    case InteresVital = 'interes_vital';
    case InteresLegitimo = 'interes_legitimo';

    /**
     * Fundarse en la ley obliga a decir en cuál. Sin la cita, la base es una
     * declaración vacía que no resiste una revisión.
     */
    public function exigeNormaHabilitante(): bool
    {
        return $this === self::FuncionLegal || $this === self::ObligacionLegal;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::FuncionLegal => 'Ejercicio de funciones legales',
            self::Consentimiento => 'Consentimiento del titular',
            self::Contrato => 'Ejecución de un contrato',
            self::ObligacionLegal => 'Cumplimiento de una obligación legal',
            self::InteresVital => 'Interés vital del titular',
            self::InteresLegitimo => 'Interés legítimo del responsable',
        };
    }
}
```

- [ ] **Step 5: Crear la excepción de dominio**

Crear `src/Privacidad/FinalidadInvalida.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use DomainException;

class FinalidadInvalida extends DomainException {}
```

- [ ] **Step 6: Crear la migración**

Crear `database/migrations/2026_08_13_000001_create_privacidad_finalidades_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de actividades de tratamiento (RAT) que exige la Ley 21.719,
 * como datos y no como documento: un Word se desactualiza en silencio, una
 * tabla que alimenta la purga y el panel no puede desactualizarse sin que se note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_finalidades', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->string('codigo');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('base_licitud');
            $table->string('norma_habilitante')->nullable();
            $table->boolean('es_accesoria')->default(false);
            $table->unsignedInteger('plazo_retencion_meses')->nullable();
            $table->json('categorias_datos')->nullable();
            $table->json('destinatarios')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['sistema', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_finalidades');
    }
};
```

- [ ] **Step 7: Crear el modelo**

Crear `src/Privacidad/Modelos/Finalidad.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\FinalidadInvalida;

/**
 * @property BaseLicitud $base_licitud
 * @property array<int, string>|null $categorias_datos
 * @property array<int, string>|null $destinatarios
 */
class Finalidad extends Model
{
    protected $table = 'privacidad_finalidades';

    protected $guarded = [];

    protected $casts = [
        'base_licitud' => BaseLicitud::class,
        'es_accesoria' => 'boolean',
        'activa' => 'boolean',
        'plazo_retencion_meses' => 'integer',
        'categorias_datos' => 'array',
        'destinatarios' => 'array',
    ];

    protected static function booted(): void
    {
        // Las invariantes se validan al guardar y no en el formulario, porque el
        // RAT también se puebla por seeders y por consola.
        static::saving(fn (Finalidad $finalidad) => $finalidad->validarInvariantes());
    }

    public function validarInvariantes(): void
    {
        if ($this->es_accesoria && $this->base_licitud !== BaseLicitud::Consentimiento) {
            throw new FinalidadInvalida(
                "La finalidad accesoria «{$this->codigo}» debe fundarse en el consentimiento: "
                .'si es separable del servicio, el titular tiene que poder negarse.',
            );
        }

        if ($this->base_licitud->exigeNormaHabilitante() && blank($this->norma_habilitante)) {
            throw new FinalidadInvalida(
                "La finalidad «{$this->codigo}» se funda en la ley pero no dice en cuál. "
                .'Indicar la norma habilitante.',
            );
        }
    }

    /** @param Builder<Finalidad> $query */
    public function scopeAccesorias(Builder $query): void
    {
        $query->where('es_accesoria', true);
    }

    /** @param Builder<Finalidad> $query */
    public function scopeDelSistema(Builder $query, string $sistema): void
    {
        $query->where('sistema', $sistema);
    }
}
```

- [ ] **Step 8: Crear la configuración**

Crear `config/privacidad.php`:

```php
<?php

return [
    // Identifica al sistema dentro del RAT compartido del ecosistema.
    'sistema' => env('PRIVACIDAD_SISTEMA', 'sistema'),

    // Plazo legal de respuesta a una solicitud ARCOP. Configurable porque debe
    // confirmarse contra el texto vigente y su reglamento antes de producción.
    'plazo_respuesta_dias' => (int) env('PRIVACIDAD_PLAZO_RESPUESTA_DIAS', 30),

    // Datos del responsable del tratamiento, que van en el RAT y en las
    // respuestas al titular. Por municipio, nunca hardcodeados.
    'responsable' => [
        'nombre' => env('PRIVACIDAD_RESPONSABLE', ''),
        'contacto' => env('PRIVACIDAD_CONTACTO', ''),
        'delegado' => env('PRIVACIDAD_DELEGADO', ''),
    ],
];
```

- [ ] **Step 9: Registrar migraciones y configuración en el provider**

Modificar `src/MuniSharedServiceProvider.php`. En `register()`, después del `mergeConfigFrom` del correo:

```php
        $this->mergeConfigFrom(__DIR__.'/../config/privacidad.php', 'privacidad');
```

Y al principio de `boot()`, antes de `$this->registrarCorreoPorGraph();`:

```php
        // Las migraciones se cargan y no se publican: así, actualizar el paquete
        // propaga el esquema a los 8 sistemas con un `migrate`, sin un paso de
        // publicación por repo que alguien va a olvidar.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/privacidad.php' => config_path('privacidad.php'),
            ], 'privacidad-config');
        }
```

- [ ] **Step 10: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/FinalidadTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 11: Verificar la suite completa y el estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: toda la suite en verde. Si Pint reclama, correr `vendor/bin/pint` y volver a verificar.

- [ ] **Step 12: Commit**

```bash
git add src/Privacidad config/privacidad.php database/migrations tests/TestCase.php tests/Privacidad src/MuniSharedServiceProvider.php
git commit -m "feat(privacidad): el RAT como datos, con base de licitud validada

Una finalidad accesoria que no se funde en el consentimiento, o una fundada
en la ley que no diga en cuál, no se puede guardar: son los dos errores que
convierten el RAT en una declaración vacía."
```

---

### Task 2: Bitácora de evidencia y contratos del módulo

**Files:**
- Create: `src/Privacidad/Contratos/TitularDeDatos.php`
- Create: `src/Privacidad/Contratos/VerificadorIdentidad.php`
- Create: `src/Privacidad/Contratos/RegistroDeEvidencia.php`
- Create: `src/Privacidad/ResultadoVerificacion.php`
- Create: `src/Privacidad/Modelos/EntradaBitacora.php`
- Create: `src/Privacidad/BitacoraEnBaseDeDatos.php`
- Create: `database/migrations/2026_08_13_000002_create_privacidad_bitacora_table.php`
- Modify: `src/MuniSharedServiceProvider.php`
- Test: `tests/Privacidad/BitacoraTest.php`

**Interfaces:**
- Consumes: nada de tareas previas.
- Produces:
  - `Muni\Shared\Privacidad\Contratos\TitularDeDatos` con `titularNombre(): string`, `titularDocumento(): string`, `exportarDatosPersonales(): array`, `purgarDatosSensibles(): void`, `anonimizar(): void`.
  - `Muni\Shared\Privacidad\Contratos\VerificadorIdentidad::verificar(array $contexto): ResultadoVerificacion`.
  - `Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia::registrar(string $evento, array $datos, ?Model $titular = null): void`.
  - `Muni\Shared\Privacidad\ResultadoVerificacion` (readonly: `bool $verificado`, `string $metodo`, `array $evidencia`).
  - Binding en el contenedor: `RegistroDeEvidencia` → `BitacoraEnBaseDeDatos`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/BitacoraTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BitacoraEnBaseDeDatos;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\ResultadoVerificacion;

it('resuelve la bitácora en base de datos como registro de evidencia por defecto', function () {
    expect(app(RegistroDeEvidencia::class))->toBeInstanceOf(BitacoraEnBaseDeDatos::class);
});

it('deja una entrada con el evento, el sistema y los datos', function () {
    config(['privacidad.sistema' => 'discapacidad']);

    app(RegistroDeEvidencia::class)->registrar('retencion.anonimizado', ['persona_id' => 7]);

    $entrada = EntradaBitacora::sole();

    expect($entrada->evento)->toBe('retencion.anonimizado')
        ->and($entrada->sistema)->toBe('discapacidad')
        ->and($entrada->datos)->toBe(['persona_id' => 7])
        ->and($entrada->ocurrido_en)->not->toBeNull();
});

it('el resultado de verificación conserva método y evidencia', function () {
    $resultado = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']);

    expect($resultado->verificado)->toBeTrue()
        ->and($resultado->metodo)->toBe('cedula_presencial')
        ->and($resultado->evidencia)->toBe(['run' => '11.111.111-1']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/BitacoraTest.php`
Expected: FAIL — `Target [RegistroDeEvidencia] is not instantiable` o clase no encontrada.

- [ ] **Step 3: Crear los contratos**

Crear `src/Privacidad/Contratos/TitularDeDatos.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Contratos;

/**
 * Lo implementa el modelo que representa a la persona en cada sistema
 * (`Persona` en los municipales, `User` donde corresponda).
 *
 * El módulo no conoce el modelo de datos de nadie: pide estas cinco cosas y el
 * sistema decide qué significan en su esquema.
 */
interface TitularDeDatos
{
    public function titularNombre(): string;

    public function titularDocumento(): string;

    /**
     * Los datos del titular para el derecho de acceso y el de portabilidad.
     *
     * @return array<string, mixed> estructura serializable a JSON y a PDF
     */
    public function exportarDatosPersonales(): array;

    /**
     * Borrado real de los datos sensibles, incluidos los archivos en disco.
     * No es soft delete: la ley pide supresión.
     */
    public function purgarDatosSensibles(): void;

    /**
     * Deja el registro sin capacidad de reidentificación, conservando lo que
     * sirve para estadística comunal.
     */
    public function anonimizar(): void;
}
```

Crear `src/Privacidad/Contratos/VerificadorIdentidad.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Contratos;

use Muni\Shared\Privacidad\ResultadoVerificacion;

/**
 * La costura que permite un único flujo ARCOP para sistemas que verifican
 * identidad de formas distintas: cédula presencial donde no hay cuentas de
 * ciudadano, sesión autenticada o Keycloak donde sí las hay.
 *
 * El módulo registra CÓMO se verificó; no decide cómo verificar.
 */
interface VerificadorIdentidad
{
    /** @param array<string, mixed> $contexto */
    public function verificar(array $contexto): ResultadoVerificacion;
}
```

Crear `src/Privacidad/Contratos/RegistroDeEvidencia.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Contratos;

use Illuminate\Database\Eloquent\Model;

interface RegistroDeEvidencia
{
    /** @param array<string, mixed> $datos */
    public function registrar(string $evento, array $datos, ?Model $titular = null): void;
}
```

- [ ] **Step 4: Crear el resultado de verificación**

Crear `src/Privacidad/ResultadoVerificacion.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

/**
 * @property-read array<string, mixed> $evidencia
 */
final class ResultadoVerificacion
{
    /** @param array<string, mixed> $evidencia */
    public function __construct(
        public readonly bool $verificado,
        public readonly string $metodo,
        public readonly array $evidencia = [],
    ) {}

    public static function fallida(string $metodo, string $motivo): self
    {
        return new self(false, $metodo, ['motivo' => $motivo]);
    }
}
```

- [ ] **Step 5: Crear la migración de la bitácora**

Crear `database/migrations/2026_08_13_000002_create_privacidad_bitacora_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia propia del módulo. No se apoya en spatie/laravel-activitylog
 * porque los sistemas del ecosistema no están en la misma versión de ese
 * paquete; donde exista, queda como segunda capa independiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_bitacora', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->string('evento');
            $table->nullableMorphs('titular');
            $table->json('datos')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('ocurrido_en');
            $table->timestamps();

            $table->index(['sistema', 'evento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_bitacora');
    }
};
```

- [ ] **Step 6: Crear el modelo y la implementación**

Crear `src/Privacidad/Modelos/EntradaBitacora.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property array<string, mixed>|null $datos
 */
class EntradaBitacora extends Model
{
    protected $table = 'privacidad_bitacora';

    protected $guarded = [];

    protected $casts = [
        'datos' => 'array',
        'ocurrido_en' => 'datetime',
    ];

    /** @return MorphTo<Model, EntradaBitacora> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }
}
```

Crear `src/Privacidad/BitacoraEnBaseDeDatos.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;

class BitacoraEnBaseDeDatos implements RegistroDeEvidencia
{
    /** @param array<string, mixed> $datos */
    public function registrar(string $evento, array $datos, ?Model $titular = null): void
    {
        EntradaBitacora::create([
            'sistema' => (string) config('privacidad.sistema'),
            'evento' => $evento,
            'titular_type' => $titular ? $titular::class : null,
            'titular_id' => $titular?->getKey(),
            'datos' => $datos,
            'user_id' => Auth::id(),
            'ocurrido_en' => now(),
        ]);
    }
}
```

- [ ] **Step 7: Enlazar el contrato en el provider**

En `register()` de `src/MuniSharedServiceProvider.php`, después del `mergeConfigFrom` de privacidad:

```php
        // Enlace por defecto: un sistema que ya tenga su propia trazabilidad
        // puede sustituirlo sin tocar el módulo.
        $this->app->bind(
            \Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia::class,
            \Muni\Shared\Privacidad\BitacoraEnBaseDeDatos::class,
        );
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/BitacoraTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 9: Verificar suite y estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: verde.

- [ ] **Step 10: Commit**

```bash
git add src/Privacidad database/migrations tests/Privacidad src/MuniSharedServiceProvider.php
git commit -m "feat(privacidad): contratos del módulo y bitácora de evidencia propia

La bitácora no se apoya en activitylog porque los sistemas no comparten
versión del paquete; el contrato deja sustituirla donde ya haya trazabilidad."
```

---

### Task 3: Consentimientos por finalidad

**Files:**
- Create: `database/migrations/2026_08_13_000003_create_privacidad_consentimientos_table.php`
- Create: `src/Privacidad/MedioDeConsentimiento.php`
- Create: `src/Privacidad/Modelos/Consentimiento.php`
- Create: `src/Privacidad/Consentimientos.php`
- Create: `tests/Privacidad/Fixtures/PersonaDePrueba.php`
- Create: `tests/Privacidad/Fixtures/migracion_personas_de_prueba.php`
- Modify: `tests/TestCase.php`
- Test: `tests/Privacidad/ConsentimientoTest.php`

**Interfaces:**
- Consumes: `Finalidad` (Task 1), `RegistroDeEvidencia` (Task 2).
- Produces: `Muni\Shared\Privacidad\Consentimientos` con `otorgar(Model $titular, Finalidad $finalidad, MedioDeConsentimiento $medio, array $opciones = []): Consentimiento`, `revocar(Model $titular, Finalidad $finalidad): void`, `vigente(Model $titular, Finalidad $finalidad): bool`. `Muni\Shared\Privacidad\MedioDeConsentimiento` (enum string: `firma_papel`, `firma_digital`, `verbal_registrada`).

- [ ] **Step 1: Write the failing test**

Crear el fixture `tests/Privacidad/Fixtures/PersonaDePrueba.php`:

```php
<?php

namespace Muni\Shared\Tests\Privacidad\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

/**
 * Titular mínimo para ejercitar el módulo sin depender del esquema de ningún
 * sistema real. Refleja lo que hace `Persona` en los municipales.
 */
class PersonaDePrueba extends Model implements TitularDeDatos
{
    protected $table = 'personas_de_prueba';

    protected $guarded = [];

    public bool $sensiblesPurgados = false;

    public bool $fueAnonimizada = false;

    public function titularNombre(): string
    {
        return (string) $this->nombre;
    }

    public function titularDocumento(): string
    {
        return (string) $this->documento;
    }

    /** @return array<string, mixed> */
    public function exportarDatosPersonales(): array
    {
        return ['nombre' => $this->nombre, 'documento' => $this->documento];
    }

    public function purgarDatosSensibles(): void
    {
        $this->forceFill(['diagnostico' => null])->save();
        $this->sensiblesPurgados = true;
    }

    public function anonimizar(): void
    {
        $this->forceFill(['nombre' => 'ANONIMIZADO', 'documento' => null])->save();
        $this->fueAnonimizada = true;
    }
}
```

Crear `tests/Privacidad/Fixtures/migracion_personas_de_prueba.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas_de_prueba', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('documento')->nullable();
            $table->string('diagnostico')->nullable();
            $table->timestamp('tratamiento_iniciado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas_de_prueba');
    }
};
```

Registrar el fixture en `tests/TestCase.php`, agregando al final de la clase:

```php
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Privacidad/Fixtures');
    }
```

Crear `tests/Privacidad/ConsentimientoTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Consentimientos;
use Muni\Shared\Privacidad\MedioDeConsentimiento;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->difusion = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'difusion',
        'nombre' => 'Difusión en redes',
        'base_licitud' => BaseLicitud::Consentimiento,
        'es_accesoria' => true,
    ]);
});

it('otorga un consentimiento vigente para una finalidad accesoria', function () {
    app(Consentimientos::class)->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);

    expect(app(Consentimientos::class)->vigente($this->titular, $this->difusion))->toBeTrue();
});

it('revocar no borra la evidencia: marca la fecha y deja de estar vigente', function () {
    $servicio = app(Consentimientos::class);
    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);

    $servicio->revocar($this->titular, $this->difusion);

    expect($servicio->vigente($this->titular, $this->difusion))->toBeFalse()
        ->and(Consentimiento::count())->toBe(1)
        ->and(Consentimiento::sole()->revocado_en)->not->toBeNull();
});

it('volver a otorgar tras una revocación deja un consentimiento vigente y conserva el anterior', function () {
    $servicio = app(Consentimientos::class);
    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::FirmaPapel);
    $servicio->revocar($this->titular, $this->difusion);

    $servicio->otorgar($this->titular, $this->difusion, MedioDeConsentimiento::VerbalRegistrada);

    expect(Consentimiento::count())->toBe(2)
        ->and($servicio->vigente($this->titular, $this->difusion))->toBeTrue();
});

it('no hay consentimiento vigente si nunca se otorgó', function () {
    expect(app(Consentimientos::class)->vigente($this->titular, $this->difusion))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/ConsentimientoTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Consentimientos" not found`.

- [ ] **Step 3: Crear el enum del medio**

Crear `src/Privacidad/MedioDeConsentimiento.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

enum MedioDeConsentimiento: string
{
    case FirmaPapel = 'firma_papel';
    case FirmaDigital = 'firma_digital';
    case VerbalRegistrada = 'verbal_registrada';
}
```

- [ ] **Step 4: Crear la migración**

Crear `database/migrations/2026_08_13_000003_create_privacidad_consentimientos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_consentimientos', function (Blueprint $table): void {
            $table->id();
            $table->morphs('titular');
            $table->foreignId('finalidad_id')->constrained('privacidad_finalidades')->cascadeOnDelete();
            $table->timestamp('otorgado_en');
            // Revocar no borra la fila: la evidencia de que hubo consentimiento
            // sigue siendo necesaria para acreditar el tratamiento pasado.
            $table->timestamp('revocado_en')->nullable();
            $table->string('medio');
            $table->string('evidencia_path')->nullable();
            $table->string('version_texto')->nullable();
            $table->string('otorgado_por')->default('titular');
            $table->foreignId('user_id')->nullable();
            $table->string('ip_hash')->nullable();
            $table->timestamps();

            $table->index(['titular_type', 'titular_id', 'finalidad_id'], 'privacidad_consentimientos_titular_finalidad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_consentimientos');
    }
};
```

- [ ] **Step 5: Crear el modelo**

Crear `src/Privacidad/Modelos/Consentimiento.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Muni\Shared\Privacidad\MedioDeConsentimiento;

/**
 * @property MedioDeConsentimiento $medio
 */
class Consentimiento extends Model
{
    protected $table = 'privacidad_consentimientos';

    protected $guarded = [];

    protected $casts = [
        'medio' => MedioDeConsentimiento::class,
        'otorgado_en' => 'datetime',
        'revocado_en' => 'datetime',
    ];

    /** @return MorphTo<Model, Consentimiento> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Finalidad, Consentimiento> */
    public function finalidad(): BelongsTo
    {
        return $this->belongsTo(Finalidad::class, 'finalidad_id');
    }

    /** @param Builder<Consentimiento> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('revocado_en');
    }
}
```

- [ ] **Step 6: Crear el servicio**

Crear `src/Privacidad/Consentimientos.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * El consentimiento solo aplica a finalidades accesorias: el registro base se
 * funda en el ejercicio de funciones legales del municipio y no se revoca, o un
 * ciudadano molesto podría borrar su propia inscripción en un registro comunal.
 */
class Consentimientos
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /** @param array<string, mixed> $opciones */
    public function otorgar(
        Model $titular,
        Finalidad $finalidad,
        MedioDeConsentimiento $medio,
        array $opciones = [],
    ): Consentimiento {
        if (! $finalidad->es_accesoria) {
            throw new FinalidadInvalida(
                "La finalidad «{$finalidad->codigo}» no es accesoria: se funda en "
                .$finalidad->base_licitud->etiqueta().' y no admite consentimiento.',
            );
        }

        // Si había uno vigente, se cierra: dos vigentes a la vez harían ambiguo
        // qué texto aceptó el titular.
        $this->revocar($titular, $finalidad);

        $consentimiento = Consentimiento::create([
            'titular_type' => $titular::class,
            'titular_id' => $titular->getKey(),
            'finalidad_id' => $finalidad->getKey(),
            'otorgado_en' => now(),
            'medio' => $medio,
            'evidencia_path' => $opciones['evidencia_path'] ?? null,
            'version_texto' => $opciones['version_texto'] ?? null,
            'otorgado_por' => $opciones['otorgado_por'] ?? 'titular',
            'ip_hash' => isset($opciones['ip']) ? hash('sha256', (string) $opciones['ip']) : null,
        ]);

        $this->evidencia->registrar('consentimiento.otorgado', [
            'finalidad' => $finalidad->codigo,
            'medio' => $medio->value,
        ], $titular);

        return $consentimiento;
    }

    public function revocar(Model $titular, Finalidad $finalidad): void
    {
        $afectados = Consentimiento::query()
            ->where('titular_type', $titular::class)
            ->where('titular_id', $titular->getKey())
            ->where('finalidad_id', $finalidad->getKey())
            ->vigentes()
            ->update(['revocado_en' => now()]);

        if ($afectados > 0) {
            $this->evidencia->registrar('consentimiento.revocado', [
                'finalidad' => $finalidad->codigo,
            ], $titular);
        }
    }

    public function vigente(Model $titular, Finalidad $finalidad): bool
    {
        return Consentimiento::query()
            ->where('titular_type', $titular::class)
            ->where('titular_id', $titular->getKey())
            ->where('finalidad_id', $finalidad->getKey())
            ->vigentes()
            ->exists();
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/ConsentimientoTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Verificar suite y estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: verde.

- [ ] **Step 9: Commit**

```bash
git add src/Privacidad database/migrations tests/Privacidad tests/TestCase.php
git commit -m "feat(privacidad): consentimiento por finalidad accesoria, revocable sin perder evidencia

Revocar escribe la fecha en vez de borrar la fila: hay que poder acreditar
que el tratamiento pasado sí tuvo consentimiento."
```

---

### Task 4: Solicitudes ARCOP con control de plazo

**Files:**
- Create: `database/migrations/2026_08_13_000004_create_privacidad_solicitudes_table.php`
- Create: `src/Privacidad/TipoDeSolicitud.php`
- Create: `src/Privacidad/EstadoDeSolicitud.php`
- Create: `src/Privacidad/Modelos/Solicitud.php`
- Create: `src/Privacidad/Solicitudes.php`
- Test: `tests/Privacidad/SolicitudTest.php`

**Interfaces:**
- Consumes: `RegistroDeEvidencia` (Task 2), `ResultadoVerificacion` (Task 2), `PersonaDePrueba` fixture (Task 3).
- Produces: `Muni\Shared\Privacidad\Solicitudes::registrar(Model $titular, TipoDeSolicitud $tipo, string $detalle, ResultadoVerificacion $verificacion, string $solicitante = 'titular'): Solicitud`; `Solicitud::$vence_en`, scope `porVencer(int $dias)`, `vencidas()`, atributo `diasRestantes(): int`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/SolicitudTest.php`:

```php
<?php

use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->verificacion = new ResultadoVerificacion(true, 'cedula_presencial', ['run' => '11.111.111-1']);
});

it('calcula el vencimiento desde el plazo configurado', function () {
    config(['privacidad.plazo_respuesta_dias' => 30]);
    $this->travelTo('2026-09-01 10:00:00');

    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Rectificacion,
        'Mi apellido está mal escrito',
        $this->verificacion,
    );

    expect($solicitud->vence_en->toDateString())->toBe('2026-10-01')
        ->and($solicitud->estado)->toBe(EstadoDeSolicitud::Recibida);
});

it('guarda cómo se verificó la identidad del solicitante', function () {
    $solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Quiero saber qué tienen de mí',
        $this->verificacion,
    );

    expect($solicitud->verificacion_identidad['metodo'])->toBe('cedula_presencial')
        ->and($solicitud->verificacion_identidad['evidencia'])->toBe(['run' => '11.111.111-1']);
});

it('rechaza registrar una solicitud si la identidad no se verificó', function () {
    $fallida = ResultadoVerificacion::fallida('cedula_presencial', 'la cédula no coincide');

    expect(fn () => app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Supresion,
        'Bórrenme',
        $fallida,
    ))->toThrow(RuntimeException::class);
});

it('lista las solicitudes por vencer y las vencidas', function () {
    config(['privacidad.plazo_respuesta_dias' => 30]);
    $this->travelTo('2026-09-01 10:00:00');
    $servicio = app(Solicitudes::class);
    $servicio->registrar($this->titular, TipoDeSolicitud::Acceso, 'A', $this->verificacion);

    $this->travelTo('2026-09-28 10:00:00');
    expect(Solicitud::porVencer(5)->count())->toBe(1)
        ->and(Solicitud::vencidas()->count())->toBe(0);

    $this->travelTo('2026-10-05 10:00:00');
    expect(Solicitud::vencidas()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/SolicitudTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Solicitudes" not found`.

- [ ] **Step 3: Crear los enums**

Crear `src/Privacidad/TipoDeSolicitud.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

/** Los derechos del titular: acceso, rectificación, supresión, oposición y portabilidad. */
enum TipoDeSolicitud: string
{
    case Acceso = 'acceso';
    case Rectificacion = 'rectificacion';
    case Supresion = 'supresion';
    case Oposicion = 'oposicion';
    case Portabilidad = 'portabilidad';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Acceso => 'Acceso',
            self::Rectificacion => 'Rectificación',
            self::Supresion => 'Supresión',
            self::Oposicion => 'Oposición',
            self::Portabilidad => 'Portabilidad',
        };
    }
}
```

Crear `src/Privacidad/EstadoDeSolicitud.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

enum EstadoDeSolicitud: string
{
    case Recibida = 'recibida';
    case EnTramite = 'en_tramite';
    case Acogida = 'acogida';
    case AcogidaParcial = 'acogida_parcial';
    case Rechazada = 'rechazada';

    public function estaResuelta(): bool
    {
        return $this === self::Acogida
            || $this === self::AcogidaParcial
            || $this === self::Rechazada;
    }
}
```

- [ ] **Step 4: Crear la migración**

Crear `database/migrations/2026_08_13_000004_create_privacidad_solicitudes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_solicitudes', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->morphs('titular');
            $table->string('tipo');
            $table->string('estado')->default('recibida');
            $table->timestamp('recibida_en');
            // El incumplimiento típico no es negarse a responder: es no responder
            // a tiempo. Por eso el vencimiento es una columna y no un cálculo.
            $table->timestamp('vence_en');
            $table->timestamp('resuelta_en')->nullable();
            $table->text('detalle');
            $table->text('fundamento_resolucion')->nullable();
            $table->json('verificacion_identidad');
            $table->string('solicitante')->default('titular');
            $table->foreignId('user_registro_id')->nullable();
            $table->foreignId('user_resolucion_id')->nullable();
            $table->string('respuesta_path')->nullable();
            $table->timestamps();

            $table->index(['estado', 'vence_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_solicitudes');
    }
};
```

- [ ] **Step 5: Crear el modelo**

Crear `src/Privacidad/Modelos/Solicitud.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

/**
 * @property TipoDeSolicitud $tipo
 * @property EstadoDeSolicitud $estado
 * @property \Illuminate\Support\Carbon $vence_en
 * @property array<string, mixed> $verificacion_identidad
 */
class Solicitud extends Model
{
    protected $table = 'privacidad_solicitudes';

    protected $guarded = [];

    protected $casts = [
        'tipo' => TipoDeSolicitud::class,
        'estado' => EstadoDeSolicitud::class,
        'recibida_en' => 'datetime',
        'vence_en' => 'datetime',
        'resuelta_en' => 'datetime',
        'verificacion_identidad' => 'array',
    ];

    /** @return MorphTo<Model, Solicitud> */
    public function titular(): MorphTo
    {
        return $this->morphTo();
    }

    public function diasRestantes(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->vence_en->startOfDay(), false);
    }

    /** @param Builder<Solicitud> $query */
    public function scopePendientes(Builder $query): void
    {
        $query->whereIn('estado', [EstadoDeSolicitud::Recibida->value, EstadoDeSolicitud::EnTramite->value]);
    }

    /** @param Builder<Solicitud> $query */
    public function scopePorVencer(Builder $query, int $dias = 5): void
    {
        $query->pendientes()
            ->whereBetween('vence_en', [now(), now()->addDays($dias)]);
    }

    /** @param Builder<Solicitud> $query */
    public function scopeVencidas(Builder $query): void
    {
        $query->pendientes()->where('vence_en', '<', now());
    }
}
```

- [ ] **Step 6: Crear el servicio**

Crear `src/Privacidad/Solicitudes.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use RuntimeException;

class Solicitudes
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    public function registrar(
        Model $titular,
        TipoDeSolicitud $tipo,
        string $detalle,
        ResultadoVerificacion $verificacion,
        string $solicitante = 'titular',
    ): Solicitud {
        // Entregar datos personales a quien no acreditó ser el titular es la
        // fuga más fácil de cometer y la más difícil de explicar después.
        if (! $verificacion->verificado) {
            throw new RuntimeException(
                'No se puede registrar la solicitud: la identidad del solicitante no fue verificada ('
                .($verificacion->evidencia['motivo'] ?? 'sin motivo').').',
            );
        }

        $solicitud = Solicitud::create([
            'sistema' => (string) config('privacidad.sistema'),
            'titular_type' => $titular::class,
            'titular_id' => $titular->getKey(),
            'tipo' => $tipo,
            'estado' => EstadoDeSolicitud::Recibida,
            'recibida_en' => now(),
            'vence_en' => now()->addDays((int) config('privacidad.plazo_respuesta_dias')),
            'detalle' => $detalle,
            'verificacion_identidad' => [
                'metodo' => $verificacion->metodo,
                'evidencia' => $verificacion->evidencia,
            ],
            'solicitante' => $solicitante,
            'user_registro_id' => Auth::id(),
        ]);

        $this->evidencia->registrar('solicitud.registrada', [
            'solicitud_id' => $solicitud->getKey(),
            'tipo' => $tipo->value,
        ], $titular);

        return $solicitud;
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/SolicitudTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Verificar suite y estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: verde.

- [ ] **Step 9: Commit**

```bash
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): solicitudes ARCOP con vencimiento e identidad acreditada

Sin verificación de identidad no se registra la solicitud: entregar datos a
quien no acreditó ser el titular es la fuga más fácil de cometer."
```

---

### Task 5: Resolución de solicitudes y exportación de datos

Cubre acceso y portabilidad, que se resuelven entregando los datos.

**Files:**
- Modify: `src/Privacidad/Solicitudes.php`
- Create: `src/Privacidad/ExportacionDeDatos.php`
- Test: `tests/Privacidad/ResolucionSolicitudTest.php`

**Interfaces:**
- Consumes: `Solicitud`, `EstadoDeSolicitud`, `TipoDeSolicitud`, `TitularDeDatos`.
- Produces: `Solicitudes::tomar(Solicitud $s): void`, `Solicitudes::acoger(Solicitud $s, string $fundamento, ?string $respuestaPath = null): void`, `Solicitudes::rechazar(Solicitud $s, string $fundamento): void`; `ExportacionDeDatos::paraTitular(TitularDeDatos $t): array` y `::comoJson(TitularDeDatos $t): string`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/ResolucionSolicitudTest.php`:

```php
<?php

use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\ExportacionDeDatos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->servicio = app(Solicitudes::class);
    $this->solicitud = $this->servicio->registrar(
        $this->titular,
        TipoDeSolicitud::Acceso,
        'Quiero saber qué tienen de mí',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );
});

it('acoger una solicitud la sella con fecha, fundamento y evidencia', function () {
    $this->servicio->acoger($this->solicitud, 'Se entregó informe impreso al titular.');

    $this->solicitud->refresh();

    expect($this->solicitud->estado)->toBe(EstadoDeSolicitud::Acogida)
        ->and($this->solicitud->resuelta_en)->not->toBeNull()
        ->and($this->solicitud->fundamento_resolucion)->toBe('Se entregó informe impreso al titular.')
        ->and(EntradaBitacora::where('evento', 'solicitud.acogida')->count())->toBe(1);
});

it('rechazar exige un fundamento y deja el estado rechazado', function () {
    $this->servicio->rechazar($this->solicitud, 'El solicitante no acredita representación del titular.');

    $this->solicitud->refresh();

    expect($this->solicitud->estado)->toBe(EstadoDeSolicitud::Rechazada)
        ->and($this->solicitud->fundamento_resolucion)->not->toBeNull();
});

it('no permite resolver dos veces la misma solicitud', function () {
    $this->servicio->acoger($this->solicitud, 'Entregado.');

    expect(fn () => $this->servicio->rechazar($this->solicitud->refresh(), 'Otra cosa'))
        ->toThrow(RuntimeException::class);
});

it('exporta los datos personales del titular para acceso y portabilidad', function () {
    $datos = app(ExportacionDeDatos::class)->paraTitular($this->titular);

    expect($datos['titular']['nombre'])->toBe('Rocío Paredes')
        ->and($datos['datos'])->toBe(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1'])
        ->and($datos['responsable'])->toHaveKey('nombre');
});

it('la exportación en json es válida y legible', function () {
    $json = app(ExportacionDeDatos::class)->comoJson($this->titular);

    expect(json_decode($json, true))->toBeArray()
        ->and($json)->toContain('Rocío Paredes'); // sin escapar unicode
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/ResolucionSolicitudTest.php`
Expected: FAIL — `Call to undefined method ...Solicitudes::acoger()`.

- [ ] **Step 3: Agregar la resolución al servicio**

Agregar a `src/Privacidad/Solicitudes.php`, dentro de la clase:

```php
    public function tomar(Solicitud $solicitud): void
    {
        $this->exigirPendiente($solicitud);

        $solicitud->update(['estado' => EstadoDeSolicitud::EnTramite]);
    }

    public function acoger(Solicitud $solicitud, string $fundamento, ?string $respuestaPath = null): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::Acogida, $fundamento, $respuestaPath);
    }

    public function acogerParcialmente(Solicitud $solicitud, string $fundamento, ?string $respuestaPath = null): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::AcogidaParcial, $fundamento, $respuestaPath);
    }

    public function rechazar(Solicitud $solicitud, string $fundamento): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::Rechazada, $fundamento);
    }

    private function resolver(
        Solicitud $solicitud,
        EstadoDeSolicitud $estado,
        string $fundamento,
        ?string $respuestaPath = null,
    ): void {
        $this->exigirPendiente($solicitud);

        if (trim($fundamento) === '') {
            throw new RuntimeException('Toda resolución debe ir fundada: es lo que se le responde al titular.');
        }

        $solicitud->update([
            'estado' => $estado,
            'resuelta_en' => now(),
            'fundamento_resolucion' => $fundamento,
            'respuesta_path' => $respuestaPath,
            'user_resolucion_id' => Auth::id(),
        ]);

        $this->evidencia->registrar("solicitud.{$estado->value}", [
            'solicitud_id' => $solicitud->getKey(),
            'tipo' => $solicitud->tipo->value,
        ], $solicitud->titular);
    }

    private function exigirPendiente(Solicitud $solicitud): void
    {
        if ($solicitud->estado->estaResuelta()) {
            throw new RuntimeException(
                "La solicitud #{$solicitud->getKey()} ya fue resuelta el "
                .$solicitud->resuelta_en?->format('d-m-Y').'. Reabrirla falsearía el registro.',
            );
        }
    }
```

- [ ] **Step 4: Crear la exportación**

Crear `src/Privacidad/ExportacionDeDatos.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

/**
 * Sirve al derecho de acceso y al de portabilidad: son el mismo dato, cambia
 * el formato en que se entrega.
 */
class ExportacionDeDatos
{
    /** @return array<string, mixed> */
    public function paraTitular(TitularDeDatos $titular): array
    {
        return [
            'generado_en' => now()->toIso8601String(),
            'responsable' => [
                'nombre' => (string) config('privacidad.responsable.nombre'),
                'contacto' => (string) config('privacidad.responsable.contacto'),
                'delegado' => (string) config('privacidad.responsable.delegado'),
            ],
            'titular' => [
                'nombre' => $titular->titularNombre(),
                'documento' => $titular->titularDocumento(),
            ],
            'datos' => $titular->exportarDatosPersonales(),
        ];
    }

    public function comoJson(TitularDeDatos $titular): string
    {
        // Sin escapar unicode: el titular tiene que poder leer su propio nombre.
        return json_encode(
            $this->paraTitular($titular),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/ResolucionSolicitudTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Verificar suite y estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: verde.

- [ ] **Step 7: Commit**

```bash
git add src/Privacidad tests/Privacidad
git commit -m "feat(privacidad): resolución fundada de solicitudes y exportación de datos

Una solicitud resuelta no se reabre y ninguna resolución va sin fundamento:
el fundamento es literalmente lo que se le responde al titular."
```

---

### Task 6: Rectificación con propagación al maestro de personas

Es el gotcha central del spec: una rectificación que no llega al maestro será pisada por la siguiente sincronización, después de que el municipio certificó por escrito que la corrigió.

**Files:**
- Create: `src/Privacidad/Contratos/PropagaRectificacion.php`
- Create: `src/Privacidad/Rectificaciones.php`
- Create: `src/Privacidad/RectificacionNoPropagada.php`
- Test: `tests/Privacidad/RectificacionTest.php`

**Interfaces:**
- Consumes: `Solicitudes` (Tasks 4-5), `Solicitud`, `TitularDeDatos`.
- Produces: `Muni\Shared\Privacidad\Contratos\PropagaRectificacion::propagar(Model $titular, array $cambios): bool`; `Muni\Shared\Privacidad\Rectificaciones::aplicar(Solicitud $solicitud, array $cambios, string $fundamento): void`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/RectificacionTest.php`:

```php
<?php

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\RectificacionNoPropagada;
use Muni\Shared\Privacidad\Rectificaciones;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\Privacidad\Solicitudes;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocio Paredez', 'documento' => '11.111.111-1']);
    $this->solicitud = app(Solicitudes::class)->registrar(
        $this->titular,
        TipoDeSolicitud::Rectificacion,
        'Mi apellido es Paredes, no Paredez',
        new ResultadoVerificacion(true, 'cedula_presencial'),
    );
});

it('aplica el cambio local y lo propaga al maestro', function () {
    $propagados = [];
    app()->bind(PropagaRectificacion::class, fn () => new class($propagados) implements PropagaRectificacion
    {
        public function __construct(public array &$vistos) {}

        public function propagar(Model $titular, array $cambios): bool
        {
            $this->vistos[] = $cambios;

            return true;
        }
    });

    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    expect($this->titular->refresh()->nombre)->toBe('Rocío Paredes')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Acogida);
});

it('si el maestro rechaza el cambio, la solicitud NO queda resuelta', function () {
    app()->bind(PropagaRectificacion::class, fn () => new class implements PropagaRectificacion
    {
        public function propagar(Model $titular, array $cambios): bool
        {
            return false;
        }
    });

    expect(fn () => app(Rectificaciones::class)->aplicar(
        $this->solicitud,
        ['nombre' => 'Rocío Paredes'],
        'Se verifica con cédula.',
    ))->toThrow(RectificacionNoPropagada::class);

    // El cambio local se revierte: quedarse con el dato corregido solo acá
    // garantiza que la próxima sincronización lo pise.
    expect($this->titular->refresh()->nombre)->toBe('Rocio Paredez')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::EnTramite);
});

it('sin propagador enlazado aplica solo local, para sistemas que no hablan con el maestro', function () {
    app(Rectificaciones::class)->aplicar($this->solicitud, ['nombre' => 'Rocío Paredes'], 'Se verifica con cédula.');

    expect($this->titular->refresh()->nombre)->toBe('Rocío Paredes')
        ->and($this->solicitud->refresh()->estado)->toBe(EstadoDeSolicitud::Acogida);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/RectificacionTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Rectificaciones" not found`.

- [ ] **Step 3: Crear el contrato y la excepción**

Crear `src/Privacidad/Contratos/PropagaRectificacion.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Contratos;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo implementa cada sistema que sea modelo de lectura del maestro de personas,
 * normalmente envolviendo `Muni\Shared\Persona\WriteThrough\SincronizarAlMaestro`.
 *
 * Devuelve false si el maestro no aceptó el cambio; en ese caso la rectificación
 * completa se revierte.
 */
interface PropagaRectificacion
{
    /** @param array<string, mixed> $cambios */
    public function propagar(Model $titular, array $cambios): bool;
}
```

Crear `src/Privacidad/RectificacionNoPropagada.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use RuntimeException;

class RectificacionNoPropagada extends RuntimeException {}
```

- [ ] **Step 4: Crear el servicio**

Crear `src/Privacidad/Rectificaciones.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;
use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * Aplicar una rectificación solo en el sistema local es peor que no aplicarla:
 * la siguiente sincronización con el maestro la pisa, y para entonces el
 * municipio ya certificó por escrito que el dato quedó corregido.
 */
class Rectificaciones
{
    public function __construct(private readonly Solicitudes $solicitudes) {}

    /** @param array<string, mixed> $cambios */
    public function aplicar(Solicitud $solicitud, array $cambios, string $fundamento): void
    {
        $titular = $solicitud->titular;

        if (! $titular instanceof Model) {
            throw new RectificacionNoPropagada(
                "La solicitud #{$solicitud->getKey()} no tiene un titular vigente al que rectificar.",
            );
        }

        $this->solicitudes->tomar($solicitud);

        DB::transaction(function () use ($titular, $cambios, $solicitud, $fundamento): void {
            $titular->forceFill($cambios)->save();

            if ($this->propagacionRechazada($titular, $cambios)) {
                // El rollback deja el dato viejo, que es honesto: el municipio no
                // puede certificar una corrección que el maestro no aceptó.
                throw new RectificacionNoPropagada(
                    'El maestro de personas rechazó la rectificación. La solicitud queda en trámite.',
                );
            }

            $this->solicitudes->acoger($solicitud, $fundamento);
        });
    }

    /** @param array<string, mixed> $cambios */
    private function propagacionRechazada(Model $titular, array $cambios): bool
    {
        // Un sistema que no es modelo de lectura del maestro no enlaza el
        // contrato: para él la rectificación local es la definitiva.
        if (! app()->bound(PropagaRectificacion::class)) {
            return false;
        }

        return ! app(PropagaRectificacion::class)->propagar($titular, $cambios);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/RectificacionTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Verificar suite y estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: verde.

- [ ] **Step 7: Commit**

```bash
git add src/Privacidad tests/Privacidad
git commit -m "feat(privacidad): la rectificación se revierte si el maestro no la acepta

Corregir solo en el sistema local garantiza que la próxima sincronización
pise el dato, ya certificada la corrección al titular."
```

---

### Task 7: Retención — anonimización y purga de sensibles

**Files:**
- Create: `src/Privacidad/AplicarRetencion.php`
- Create: `src/Privacidad/Console/AplicarRetencionCommand.php`
- Create: `src/Privacidad/Contratos/ResuelveTitularesVencidos.php`
- Modify: `src/MuniSharedServiceProvider.php`
- Test: `tests/Privacidad/RetencionTest.php`

**Interfaces:**
- Consumes: `Finalidad` (Task 1), `TitularDeDatos` y `RegistroDeEvidencia` (Task 2).
- Produces: `Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos::vencidos(Finalidad $finalidad): iterable`; `Muni\Shared\Privacidad\NingunTitularVencido` (enlace por defecto del contrato); `Muni\Shared\Privacidad\AplicarRetencion::ejecutar(bool $simulacion = true): array` devolviendo `['finalidad' => codigo, 'titulares' => int]` por finalidad; comando `privacidad:aplicar-retencion` con flag `--ejecutar`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/RetencionTest.php`:

```php
<?php

use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'atencion',
        'nombre' => 'Atención de casos',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 60,
    ]);

    $this->vencida = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subYears(6),
    ]);

    app()->bind(ResuelveTitularesVencidos::class, fn () => new class implements ResuelveTitularesVencidos
    {
        public function vencidos(Finalidad $finalidad): iterable
        {
            return PersonaDePrueba::query()
                ->whereNotNull('tratamiento_iniciado_en')
                ->where('tratamiento_iniciado_en', '<', now()->subMonths((int) $finalidad->plazo_retencion_meses))
                ->get();
        }
    });
});

it('en simulación informa a quién tocaría sin tocar nada', function () {
    $resumen = app(AplicarRetencion::class)->ejecutar(simulacion: true);

    expect($resumen)->toBe([['finalidad' => 'atencion', 'titulares' => 1]])
        ->and($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud')
        ->and($this->vencida->refresh()->nombre)->toBe('Rocío Paredes')
        ->and(EntradaBitacora::count())->toBe(0);
});

it('al ejecutar purga los sensibles y anonimiza, dejando evidencia', function () {
    app(AplicarRetencion::class)->ejecutar(simulacion: false);

    $this->vencida->refresh();

    expect($this->vencida->diagnostico)->toBeNull()
        ->and($this->vencida->nombre)->toBe('ANONIMIZADO')
        ->and($this->vencida->documento)->toBeNull()
        ->and(EntradaBitacora::where('evento', 'retencion.aplicada')->count())->toBe(1);
});

it('ignora finalidades sin plazo de retención', function () {
    $this->finalidad->update(['plazo_retencion_meses' => null]);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false))->toBe([]);
    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});

it('el comando no toca nada sin --ejecutar', function () {
    $this->artisan('privacidad:aplicar-retencion')
        ->expectsOutputToContain('simulación')
        ->assertSuccessful();

    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});

it('el comando con --ejecutar aplica la retención', function () {
    $this->artisan('privacidad:aplicar-retencion --ejecutar')->assertSuccessful();

    expect($this->vencida->refresh()->nombre)->toBe('ANONIMIZADO');
});

it('un sistema que no implementó el resolvedor no purga nada en vez de reventar', function () {
    // Se deshace el enlace del beforeEach para simular un sistema recién instalado.
    app()->forgetInstance(ResuelveTitularesVencidos::class);
    app()->bind(ResuelveTitularesVencidos::class, NingunTitularVencido::class);

    expect(app(AplicarRetencion::class)->ejecutar(simulacion: false))->toBe([]);
    expect($this->vencida->refresh()->diagnostico)->toBe('dato sensible de salud');
});
```

Agregar el import correspondiente al inicio del archivo de prueba:

```php
use Muni\Shared\Privacidad\NingunTitularVencido;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/RetencionTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\AplicarRetencion" not found`.

- [ ] **Step 3: Crear el contrato del resolvedor**

Crear `src/Privacidad/Contratos/ResuelveTitularesVencidos.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Contratos;

use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * Cada sistema sabe desde cuándo trata a un titular bajo una finalidad: puede
 * ser la fecha de registro, la última atención o el cierre del caso. El módulo
 * no puede adivinarlo, así que lo pregunta.
 *
 * @method iterable<int, TitularDeDatos> vencidos(Finalidad $finalidad)
 */
interface ResuelveTitularesVencidos
{
    /** @return iterable<int, TitularDeDatos> */
    public function vencidos(Finalidad $finalidad): iterable;
}
```

- [ ] **Step 4: Crear el resolvedor por defecto**

Crear `src/Privacidad/NingunTitularVencido.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * Enlace por defecto del contrato. Un sistema recién instalado que todavía no
 * definió desde cuándo trata a cada titular no debe reventar al correr el
 * comando: debe no purgar nada, que es el fallo seguro.
 */
class NingunTitularVencido implements ResuelveTitularesVencidos
{
    /** @return iterable<int, \Muni\Shared\Privacidad\Contratos\TitularDeDatos> */
    public function vencidos(Finalidad $finalidad): iterable
    {
        return [];
    }
}
```

Enlazarlo en `register()` de `src/MuniSharedServiceProvider.php`, junto al de `RegistroDeEvidencia`:

```php
        $this->app->bind(
            \Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos::class,
            \Muni\Shared\Privacidad\NingunTitularVencido::class,
        );
```

- [ ] **Step 5: Crear el servicio**

Crear `src/Privacidad/AplicarRetencion.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * La ley pide suprimir cuando el dato ya no es necesario para la finalidad.
 * Acá eso son dos cosas distintas: los sensibles se borran de verdad y el
 * registro se anonimiza, para no perder la serie estadística comunal.
 */
class AplicarRetencion
{
    public function __construct(
        private readonly RegistroDeEvidencia $evidencia,
        private readonly ResuelveTitularesVencidos $resolvedor,
    ) {}

    /**
     * @return array<int, array{finalidad: string, titulares: int}>
     */
    public function ejecutar(bool $simulacion = true): array
    {
        $resumen = [];

        $finalidades = Finalidad::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('activa', true)
            ->whereNotNull('plazo_retencion_meses')
            ->get();

        foreach ($finalidades as $finalidad) {
            $contados = 0;

            foreach ($this->resolvedor->vencidos($finalidad) as $titular) {
                $contados++;

                if ($simulacion) {
                    continue;
                }

                $this->aplicarA($titular, $finalidad);
            }

            if ($contados > 0) {
                $resumen[] = ['finalidad' => (string) $finalidad->codigo, 'titulares' => $contados];
            }
        }

        return $resumen;
    }

    private function aplicarA(TitularDeDatos $titular, Finalidad $finalidad): void
    {
        // El orden importa: primero se borra lo sensible, después se anonimiza.
        // Al revés, el registro anonimizado podría conservar el archivo sensible
        // sin nadie a quien asociarlo para borrarlo después.
        $titular->purgarDatosSensibles();
        $titular->anonimizar();

        $this->evidencia->registrar('retencion.aplicada', [
            'finalidad' => $finalidad->codigo,
            'plazo_meses' => $finalidad->plazo_retencion_meses,
        ], $titular instanceof Model ? $titular : null);
    }
}
```

- [ ] **Step 6: Crear el comando**

Crear `src/Privacidad/Console/AplicarRetencionCommand.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Muni\Shared\Privacidad\AplicarRetencion;

class AplicarRetencionCommand extends Command
{
    protected $signature = 'privacidad:aplicar-retencion {--ejecutar : Aplica los cambios de verdad}';

    protected $description = 'Anonimiza y purga los datos cuyo plazo de retención venció';

    public function handle(AplicarRetencion $retencion): int
    {
        // El destructivo es opt-in: nadie descubre en producción que un cron
        // llevaba semanas borrando lo que no correspondía.
        $simulacion = ! $this->option('ejecutar');

        if ($simulacion) {
            $this->warn('Modo simulación: no se modificará ningún dato. Usar --ejecutar para aplicar.');
        }

        $resumen = $retencion->ejecutar($simulacion);

        if ($resumen === []) {
            $this->info('No hay titulares con plazo de retención vencido.');

            return self::SUCCESS;
        }

        $this->table(['Finalidad', 'Titulares'], array_map(
            fn (array $fila): array => [$fila['finalidad'], $fila['titulares']],
            $resumen,
        ));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Registrar el comando**

En `src/MuniSharedServiceProvider.php`, agregar el import y sumar `AplicarRetencionCommand::class` al array de `$this->commands([...])` existente en `boot()`.

```php
use Muni\Shared\Privacidad\Console\AplicarRetencionCommand;
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/RetencionTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 9: Verificar suite y estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: verde.

- [ ] **Step 10: Commit**

```bash
git add src/Privacidad tests/Privacidad src/MuniSharedServiceProvider.php
git commit -m "feat(privacidad): retención que anonimiza y purga, en simulación por defecto

Lo destructivo es opt-in con --ejecutar: nadie debería descubrir en
producción que un cron llevaba semanas borrando lo que no correspondía."
```

---

### Task 8: Registro de brechas

**Files:**
- Create: `database/migrations/2026_08_13_000005_create_privacidad_brechas_table.php`
- Create: `src/Privacidad/Modelos/Brecha.php`
- Create: `src/Privacidad/Brechas.php`
- Test: `tests/Privacidad/BrechaTest.php`

**Interfaces:**
- Consumes: `RegistroDeEvidencia` (Task 2).
- Produces: `Muni\Shared\Privacidad\Brechas::registrar(string $descripcion, array $datos = []): Brecha`, `::notificarAgencia(Brecha $b): void`, `::notificarTitulares(Brecha $b): void`; scope `Brecha::sinNotificar()`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/BrechaTest.php`:

```php
<?php

use Muni\Shared\Privacidad\Brechas;
use Muni\Shared\Privacidad\Modelos\Brecha;

it('registra una brecha con su naturaleza y alcance', function () {
    $brecha = app(Brechas::class)->registrar('Acceso indebido a fichas de salud', [
        'naturaleza' => 'acceso_no_autorizado',
        'categorias_afectadas' => ['salud', 'identificacion'],
        'titulares_estimados' => 12,
        'riesgo_alto' => true,
    ]);

    expect($brecha->riesgo_alto)->toBeTrue()
        ->and($brecha->categorias_afectadas)->toBe(['salud', 'identificacion'])
        ->and($brecha->detectada_en)->not->toBeNull();
});

it('sella las dos notificaciones por separado', function () {
    $servicio = app(Brechas::class);
    $brecha = $servicio->registrar('Respaldo extraviado', ['riesgo_alto' => true]);

    $servicio->notificarAgencia($brecha);

    expect($brecha->refresh()->notificada_agencia_en)->not->toBeNull()
        ->and($brecha->notificada_titulares_en)->toBeNull();

    $servicio->notificarTitulares($brecha);

    expect($brecha->refresh()->notificada_titulares_en)->not->toBeNull();
});

it('lista las brechas de riesgo alto que aún no se notifican a la Agencia', function () {
    $servicio = app(Brechas::class);
    $servicio->registrar('Sin notificar', ['riesgo_alto' => true]);
    $notificada = $servicio->registrar('Ya notificada', ['riesgo_alto' => true]);
    $servicio->notificarAgencia($notificada);

    expect(Brecha::sinNotificar()->pluck('descripcion')->all())->toBe(['Sin notificar']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/BrechaTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Brechas" not found`.

- [ ] **Step 3: Crear la migración**

Crear `database/migrations/2026_08_13_000005_create_privacidad_brechas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacidad_brechas', function (Blueprint $table): void {
            $table->id();
            $table->string('sistema');
            $table->timestamp('detectada_en');
            $table->text('descripcion');
            $table->string('naturaleza')->nullable();
            $table->json('categorias_afectadas')->nullable();
            $table->unsignedInteger('titulares_estimados')->nullable();
            $table->boolean('riesgo_alto')->default(false);
            $table->text('medidas')->nullable();
            // Dos hitos distintos y con destinatarios distintos: la Agencia
            // siempre, los titulares solo cuando el riesgo es alto.
            $table->timestamp('notificada_agencia_en')->nullable();
            $table->timestamp('notificada_titulares_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacidad_brechas');
    }
};
```

- [ ] **Step 4: Crear el modelo**

Crear `src/Privacidad/Modelos/Brecha.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Modelos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<int, string>|null $categorias_afectadas
 */
class Brecha extends Model
{
    protected $table = 'privacidad_brechas';

    protected $guarded = [];

    protected $casts = [
        'detectada_en' => 'datetime',
        'categorias_afectadas' => 'array',
        'riesgo_alto' => 'boolean',
        'titulares_estimados' => 'integer',
        'notificada_agencia_en' => 'datetime',
        'notificada_titulares_en' => 'datetime',
    ];

    /** @param Builder<Brecha> $query */
    public function scopeSinNotificar(Builder $query): void
    {
        $query->whereNull('notificada_agencia_en');
    }
}
```

- [ ] **Step 5: Crear el servicio**

Crear `src/Privacidad/Brechas.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Brecha;

/**
 * El canal oficial de la Agencia todavía no existe, así que el módulo no
 * automatiza el envío: registra los hitos y deja el informe listo, que es lo
 * que se pide acreditar.
 */
class Brechas
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /** @param array<string, mixed> $datos */
    public function registrar(string $descripcion, array $datos = []): Brecha
    {
        $brecha = Brecha::create([
            'sistema' => (string) config('privacidad.sistema'),
            'detectada_en' => $datos['detectada_en'] ?? now(),
            'descripcion' => $descripcion,
            'naturaleza' => $datos['naturaleza'] ?? null,
            'categorias_afectadas' => $datos['categorias_afectadas'] ?? null,
            'titulares_estimados' => $datos['titulares_estimados'] ?? null,
            'riesgo_alto' => (bool) ($datos['riesgo_alto'] ?? false),
            'medidas' => $datos['medidas'] ?? null,
        ]);

        $this->evidencia->registrar('brecha.registrada', ['brecha_id' => $brecha->getKey()]);

        return $brecha;
    }

    public function notificarAgencia(Brecha $brecha): void
    {
        $brecha->update(['notificada_agencia_en' => now()]);
        $this->evidencia->registrar('brecha.notificada_agencia', ['brecha_id' => $brecha->getKey()]);
    }

    public function notificarTitulares(Brecha $brecha): void
    {
        $brecha->update(['notificada_titulares_en' => now()]);
        $this->evidencia->registrar('brecha.notificada_titulares', ['brecha_id' => $brecha->getKey()]);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/BrechaTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Verificar suite y estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: verde.

- [ ] **Step 8: Commit**

```bash
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): registro de brechas con sus dos hitos de notificación

La Agencia siempre, los titulares solo si el riesgo es alto: son fechas
distintas y hay que poder acreditar cada una por separado."
```

---

### Task 9: Exportación del RAT y documentación de instalación

**Files:**
- Create: `src/Privacidad/Console/ExportarRatCommand.php`
- Create: `stubs/privacidad/eipd.md`
- Create: `stubs/privacidad/politica-de-privacidad.md`
- Create: `stubs/privacidad/procedimiento-de-brechas.md`
- Modify: `src/MuniSharedServiceProvider.php`
- Modify: `README.md`
- Test: `tests/Privacidad/ExportarRatTest.php`

**Interfaces:**
- Consumes: `Finalidad` (Task 1).
- Produces: comando `privacidad:rat {--json}`; stubs publicables con tag `privacidad-stubs`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/ExportarRatTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(function () {
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

it('imprime el RAT del sistema con su base de licitud y norma', function () {
    $this->artisan('privacidad:rat')
        ->expectsOutputToContain('registro_comunal')
        ->expectsOutputToContain('Ley 20.422')
        ->assertSuccessful();
});

it('exporta el RAT en json con el responsable del tratamiento', function () {
    $this->artisan('privacidad:rat --json')->assertSuccessful();

    $salida = \Illuminate\Support\Facades\Artisan::output();
    $rat = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);

    expect($rat['responsable']['nombre'])->toBe('I. Municipalidad de Graneros')
        ->and($rat['finalidades'][0]['codigo'])->toBe('registro_comunal')
        ->and($rat['finalidades'][0]['base_licitud'])->toBe('funcion_legal');
});

it('avisa cuando el sistema no declaró ninguna finalidad', function () {
    Finalidad::query()->delete();

    $this->artisan('privacidad:rat')
        ->expectsOutputToContain('no declaró')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/ExportarRatTest.php`
Expected: FAIL — `The command "privacidad:rat" does not exist`.

- [ ] **Step 3: Crear el comando**

Crear `src/Privacidad/Console/ExportarRatCommand.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * El registro de actividades de tratamiento es lo primero que pide una
 * fiscalización. Se genera desde la base y no desde un documento, para que no
 * pueda quedar desactualizado sin que nadie se entere.
 */
class ExportarRatCommand extends Command
{
    protected $signature = 'privacidad:rat {--json : Emite el RAT en JSON}';

    protected $description = 'Exporta el registro de actividades de tratamiento del sistema';

    public function handle(): int
    {
        $sistema = (string) config('privacidad.sistema');

        $finalidades = Finalidad::query()->delSistema($sistema)->orderBy('codigo')->get();

        if ($finalidades->isEmpty()) {
            $this->warn("El sistema «{$sistema}» no declaró ninguna finalidad de tratamiento.");

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'sistema' => $sistema,
                'generado_en' => now()->toIso8601String(),
                'responsable' => config('privacidad.responsable'),
                'finalidades' => $finalidades->map(fn (Finalidad $f): array => [
                    'codigo' => $f->codigo,
                    'nombre' => $f->nombre,
                    'base_licitud' => $f->base_licitud->value,
                    'norma_habilitante' => $f->norma_habilitante,
                    'es_accesoria' => $f->es_accesoria,
                    'plazo_retencion_meses' => $f->plazo_retencion_meses,
                    'categorias_datos' => $f->categorias_datos,
                    'destinatarios' => $f->destinatarios,
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("RAT del sistema «{$sistema}» — ".config('privacidad.responsable.nombre'));

        $this->table(
            ['Código', 'Finalidad', 'Base de licitud', 'Norma', 'Retención (meses)'],
            $finalidades->map(fn (Finalidad $f): array => [
                $f->codigo,
                $f->nombre,
                $f->base_licitud->etiqueta(),
                $f->norma_habilitante ?? '—',
                $f->plazo_retencion_meses ?? 'sin plazo',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registrar el comando y los stubs**

En `src/MuniSharedServiceProvider.php`: importar `ExportarRatCommand`, sumarlo al array de `$this->commands([...])`, y agregar al bloque `publishes` de `boot()`:

```php
            $this->publishes([
                __DIR__.'/../stubs/privacidad' => base_path('docs/privacidad'),
            ], 'privacidad-stubs');
```

- [ ] **Step 5: Crear los stubs de documentos**

Crear `stubs/privacidad/eipd.md`:

```markdown
# Evaluación de impacto en protección de datos

> Completar por el responsable del tratamiento. Exigible cuando el tratamiento
> de datos sensibles es masivo y sistemático, como el registro comunal de
> personas con discapacidad.

## 1. Responsable del tratamiento
Nombre:
Contacto:
Delegado de protección de datos:

## 2. Descripción del tratamiento
Obtener el listado vigente de finalidades con: `php artisan privacidad:rat`

## 3. Necesidad y proporcionalidad
¿Por qué no basta un tratamiento menos invasivo para la misma finalidad?

## 4. Riesgos identificados para los derechos de los titulares

| Riesgo | Probabilidad | Impacto | Medida mitigadora | Evidencia en el sistema |
|---|---|---|---|---|

## 5. Medidas técnicas ya implementadas
- Cifrado de los campos sensibles en reposo
- Control de acceso por rol y separación de funciones
- Trazabilidad de accesos y resoluciones (`privacidad_bitacora`)
- Retención con anonimización automática (`privacidad:aplicar-retencion`)
- Segundo factor obligatorio para el personal del panel

## 6. Conclusión y fecha de revisión
```

Crear `stubs/privacidad/politica-de-privacidad.md`:

```markdown
# Política de privacidad

> Texto publicable para el sitio del municipio. Completar y publicar; la ley
> exige que el titular pueda conocer estas condiciones antes del tratamiento.

## Quién trata sus datos
## Qué datos tratamos y con qué finalidad
## En qué nos fundamos para tratarlos
## Con quién se comparten
## Por cuánto tiempo los conservamos
## Cómo ejercer sus derechos
Acceso, rectificación, supresión, oposición y portabilidad se solicitan
presencialmente en [oficina], acreditando identidad con cédula. El plazo de
respuesta es de [plazo] días.

## Cómo reclamar ante la Agencia de Protección de Datos Personales
```

Crear `stubs/privacidad/procedimiento-de-brechas.md`:

```markdown
# Procedimiento ante una brecha de datos personales

## 1. Detección y contención (inmediato)
Quien detecte la brecha avisa al responsable. Contener antes de investigar.

## 2. Registro (mismo día)
Registrar la brecha en el panel de Privacidad con: naturaleza, categorías de
datos afectadas, número estimado de titulares y medidas adoptadas.

## 3. Evaluación de riesgo
Si la brecha afecta datos sensibles o puede causar perjuicio al titular, se
marca como riesgo alto.

## 4. Notificación a la Agencia
Sin dilaciones indebidas. Sellar la fecha en el panel.

## 5. Notificación a los titulares
Obligatoria cuando el riesgo es alto. Sellar la fecha en el panel.

## 6. Revisión posterior
Qué falló, qué se cambió para que no vuelva a pasar.
```

- [ ] **Step 6: Documentar la instalación en el README**

Agregar al final de `README.md`:

```markdown
## Módulo Privacidad (Ley 21.719)

Cubre el registro de actividades de tratamiento, el consentimiento por
finalidad, los derechos ARCOP con control de plazo, la retención con supresión
efectiva y el registro de brechas.

### Instalar en un sistema

```bash
composer update muni-graneros/laravel-muni-shared
php artisan migrate
php artisan vendor:publish --tag=privacidad-config
php artisan vendor:publish --tag=privacidad-stubs
```

En el `.env`:

```
PRIVACIDAD_SISTEMA=discapacidad
PRIVACIDAD_PLAZO_RESPUESTA_DIAS=30
PRIVACIDAD_RESPONSABLE="I. Municipalidad de Graneros"
PRIVACIDAD_CONTACTO=privacidad@municipalidadgraneros.cl
PRIVACIDAD_DELEGADO=
```

### Lo que cada sistema debe aportar

| Contrato | Obligatorio | Qué resuelve |
|---|---|---|
| `TitularDeDatos` | Sí | Cómo se exporta, purga y anonimiza a una persona |
| `ResuelveTitularesVencidos` | Solo si hay retención | Desde cuándo se trata a un titular bajo cada finalidad |
| `VerificadorIdentidad` | Sí | Cómo se acredita que el solicitante es el titular |
| `PropagaRectificacion` | Solo si es modelo de lectura del maestro | Que la rectificación no la pise la próxima sincronización |
| `RegistroDeEvidencia` | No | Sustituir la bitácora propia por la del sistema |

Además, cada sistema siembra sus finalidades: es donde declara qué trata, con
qué base y por cuánto tiempo.

### Comandos

```bash
php artisan privacidad:rat                        # el RAT en tabla
php artisan privacidad:rat --json                 # el RAT para adjuntar
php artisan privacidad:aplicar-retencion          # simulación
php artisan privacidad:aplicar-retencion --ejecutar
```
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/ExportarRatTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 8: Verificar suite completa y estilo**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: toda la suite del paquete en verde.

- [ ] **Step 9: Commit**

```bash
git add src/Privacidad stubs README.md tests/Privacidad src/MuniSharedServiceProvider.php
git commit -m "feat(privacidad): RAT exportable, plantillas de EIPD y política, e instalación documentada

El RAT se genera desde la base y no desde un documento: así no puede quedar
desactualizado sin que nadie se entere."
```

---

### Task 10: Publicar la versión 1.12.0

**Files:**
- Modify: `README.md` (encabezado de versión, si lo tiene)

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: tag `v1.12.0` en el remoto, consumible por los 8 sistemas.

- [ ] **Step 1: Verificar que la suite completa está en verde**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: verde. Si algo falla, no se etiqueta.

- [ ] **Step 2: Verificar que las migraciones corren limpias desde cero**

Run: `vendor/bin/pest --filter=Privacidad`
Expected: verde. `RefreshDatabase` recrea el esquema en cada test, así que un
choque de nombres o una FK mal puesta se manifiesta acá.

- [ ] **Step 3: Confirmar que no se coló una dependencia prohibida**

Run: `grep -rn "Filament\\\\\|Spatie\\\\Activitylog" src/Privacidad || echo "sin dependencias prohibidas"`
Expected: `sin dependencias prohibidas`.

- [ ] **Step 4: Fusionar a main y etiquetar**

```bash
git checkout main
git merge --no-ff feat/privacidad-21719 -m "merge: módulo Privacidad (Ley 21.719)"
git tag -a v1.12.0 -m "Módulo Privacidad: RAT, consentimiento, ARCOP, retención y brechas"
```

- [ ] **Step 5: Empujar**

```bash
git push origin main --follow-tags
```

Verificar antes que la credencial en uso sea la cuenta correcta: el keyring
puede tener cacheada otra y el error es «repositorio no encontrado».

---

## Notas para quien ejecute

- **Nada de Filament acá.** El panel es el Plan 2, en `laravel-muni-ui`.
- **Nada de tocar los sistemas.** La adopción es el Plan 3.
- Si una tarea revela que el diseño del spec no cierra, parar y decirlo. No improvisar una solución distinta en silencio: hay decisiones jurídicas detrás de varias de estas estructuras.
- Los enums se guardan por `value`. Si un test compara contra el string crudo en base, usar el `->value`.
- `$this->travelTo()` viene de `InteractsWithTime`, ya disponible en el `TestCase` de Testbench: no hay que importar nada.
- La suite completa al terminar el plan debe quedar en **37 tests** del módulo (4+3+4+4+5+3+6+3+3) más los que ya tenía el paquete. Si el total no cuadra, falta o sobra algo.
