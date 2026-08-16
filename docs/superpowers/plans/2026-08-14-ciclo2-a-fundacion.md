# Ciclo 2 — Plan A: fundación (bitácora, dato sensible, plazo de brechas)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar la bitácora inmutable y desvinculable, y cerrar los dos huecos baratos del ciclo 2 (excepción de dato sensible, plazo de notificación de brechas).

**Architecture:** Va primero porque la bitácora es la pieza donde escriben los seis servicios del módulo: cambiarla después obliga a migrar lo ya escrito. Los otros dos huecos entran acá por ser una columna con invariante y una columna con scopes — no justifican un plan propio.

**Tech Stack:** PHP 8.3+, Laravel 11/12/13, Pest 3/4, Orchestra Testbench, SQLite en tests.

**Spec:** `docs/superpowers/specs/2026-08-14-ley-21719-ciclo2-design.md` (huecos 9, 3 y 6)

## Global Constraints

- Namespace `Muni\Shared\Privacidad\`. Tablas con prefijo `privacidad_`.
- Sin dependencia de `filament/filament` ni `spatie/laravel-activitylog`.
- `illuminate/* ^11.0|^12.0|^13.0`, `php ^8.3`. Nada de sintaxis posterior.
- Texto de dominio, tablas, columnas y mensajes en español. Los comentarios explican el *porqué*.
- Tests en Pest, en español (`it('...')`), como los del ciclo 1.
- Excepciones de dominio propias, nunca `RuntimeException` pelada (convención del ciclo 1: `FinalidadInvalida`, `IdentidadNoVerificada`, `ResolucionInvalida`, `RectificacionNoPropagada`).
- Servicios que mutan y registran evidencia envuelven ambas cosas en `DB::transaction()`.
- La suite parte en **119 tests**. Cada tarea termina con `vendor/bin/pest` y `vendor/bin/pint --test` en verde.
- Commits en español, sin atribución a IA. **No pushear, no mergear, no etiquetar, no tocar ningún remoto.**

## Migraciones ya existentes (no reordenar)

`2026_08_13_00000{1..6}`. Las nuevas van `2026_08_14_0000{1,2,3}`.

---

### Task 1: La bitácora es append-only

Hoy `EntradaBitacora` es un modelo mutable corriente: cualquiera con acceso a la base puede editar el registro de evidencia. Es el hueco que el ciclo 1 dejó aparcado.

**Files:**
- Create: `src/Privacidad/BitacoraInmutable.php`
- Modify: `src/Privacidad/Modelos/EntradaBitacora.php`
- Test: `tests/Privacidad/BitacoraInmutableTest.php`

**Interfaces:**
- Consumes: `EntradaBitacora` (ciclo 1).
- Produces: `Muni\Shared\Privacidad\BitacoraInmutable extends \DomainException`; `EntradaBitacora` rechaza `update` y `delete`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/BitacoraInmutableTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BitacoraInmutable;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    app(RegistroDeEvidencia::class)->registrar('prueba.evento', ['campo' => 'valor']);
    $this->entrada = EntradaBitacora::sole();
});

it('rechaza modificar una entrada ya escrita', function () {
    expect(fn () => $this->entrada->update(['evento' => 'otro.evento']))
        ->toThrow(BitacoraInmutable::class);
});

it('rechaza borrar una entrada', function () {
    expect(fn () => $this->entrada->delete())->toThrow(BitacoraInmutable::class);
});

it('la entrada sigue intacta después de los intentos', function () {
    rescue(fn () => $this->entrada->update(['evento' => 'otro.evento']));
    rescue(fn () => $this->entrada->delete());

    expect(EntradaBitacora::count())->toBe(1)
        ->and(EntradaBitacora::sole()->evento)->toBe('prueba.evento');
});

it('sigue permitiendo escribir entradas nuevas', function () {
    app(RegistroDeEvidencia::class)->registrar('otro.evento', []);

    expect(EntradaBitacora::count())->toBe(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/BitacoraInmutableTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\BitacoraInmutable" not found`.

- [ ] **Step 3: Crear la excepción**

Crear `src/Privacidad/BitacoraInmutable.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use DomainException;

/**
 * Se lanza al intentar alterar una entrada de la bitácora.
 *
 * La bitácora es lo que el municipio muestra ante una fiscalización: un registro
 * de evidencia que se puede editar no acredita nada.
 */
class BitacoraInmutable extends DomainException {}
```

- [ ] **Step 4: Sellar el modelo**

En `src/Privacidad/Modelos/EntradaBitacora.php`, agregar:

```php
    protected static function booted(): void
    {
        // Append-only. La única mutación permitida es cortar el vínculo con el
        // titular al anonimizarlo, y esa va por query builder desde
        // Bitacora::desvincular(), que no dispara eventos de modelo y deja su
        // propia entrada registrando que ocurrió.
        static::updating(function (): never {
            throw new BitacoraInmutable(
                'Una entrada de bitácora no se modifica: es el registro de evidencia del tratamiento.',
            );
        });

        static::deleting(function (): never {
            throw new BitacoraInmutable(
                'Una entrada de bitácora no se borra. Para desvincularla de un titular anonimizado, '
                .'usar Bitacora::desvincular().',
            );
        });
    }
```

Con su `use Muni\Shared\Privacidad\BitacoraInmutable;`.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/BitacoraInmutableTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Suite completa**

Run: `vendor/bin/pest && vendor/bin/pint --test`
Expected: 123 tests verdes. **Si algún test del ciclo 1 se cae acá, es un hallazgo real**: significa que algo estaba modificando entradas de bitácora sin que nos diéramos cuenta. Reportarlo, no relajar el candado.

- [ ] **Step 7: Commit**

```bash
git add src/Privacidad tests/Privacidad
git commit -m "feat(privacidad): la bitácora es append-only

Un registro de evidencia que se puede editar no acredita nada."
```

---

### Task 2: Desvincular la bitácora del titular anonimizado

`privacidad_bitacora` tiene un morph al titular. Después de `anonimizar()` esa relación sigue apuntando a la persona: se anonimiza el registro y se deja intacto el índice que lleva hasta él.

**Files:**
- Create: `database/migrations/2026_08_14_000001_add_titular_ref_a_privacidad_bitacora.php`
- Create: `src/Privacidad/Bitacora.php`
- Modify: `src/Privacidad/Modelos/EntradaBitacora.php`
- Modify: `src/Privacidad/AplicarRetencion.php`
- Test: `tests/Privacidad/BitacoraDesvincularTest.php`

**Interfaces:**
- Consumes: `EntradaBitacora`, `AplicarRetencion` (ciclo 1).
- Produces: `Muni\Shared\Privacidad\Bitacora::desvincular(Model $titular): int` — devuelve cuántas entradas desvinculó.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/BitacoraDesvincularTest.php`:

```php
<?php

use Muni\Shared\Privacidad\Bitacora;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->titular = PersonaDePrueba::create(['nombre' => 'Rocío Paredes', 'documento' => '11.111.111-1']);
    $this->otro = PersonaDePrueba::create(['nombre' => 'Otro Titular', 'documento' => '22.222.222-2']);

    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['a' => 1], $this->titular);
    app(RegistroDeEvidencia::class)->registrar('solicitud.acogida', ['b' => 2], $this->titular);
    app(RegistroDeEvidencia::class)->registrar('solicitud.registrada', ['c' => 3], $this->otro);
});

it('corta el vínculo con el titular pero conserva las entradas', function () {
    $desvinculadas = app(Bitacora::class)->desvincular($this->titular);

    expect($desvinculadas)->toBe(2)
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

it('deja constancia de la propia desvinculación', function () {
    app(Bitacora::class)->desvincular($this->titular);

    expect(EntradaBitacora::where('evento', 'bitacora.desvinculada')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/BitacoraDesvincularTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Bitacora" not found`.

- [ ] **Step 3: Crear la migración**

Crear `database/migrations/2026_08_14_000001_add_titular_ref_a_privacidad_bitacora.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referencia opaca al titular, para después de anonimizarlo.
 *
 * Es un valor ALEATORIO generado al desvincular, no un hash del identificador:
 * un hash con la lista de ids se revierte por fuerza bruta, y entonces la
 * anonimización sería decorativa. Permite agrupar las entradas de un mismo caso
 * sin ninguna forma de volver a la persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_bitacora', function (Blueprint $table): void {
            $table->string('titular_ref', 26)->nullable()->after('titular_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_bitacora', function (Blueprint $table): void {
            $table->dropColumn('titular_ref');
        });
    }
};
```

- [ ] **Step 4: Exponer la columna en el modelo**

En `src/Privacidad/Modelos/EntradaBitacora.php`, agregar `titular_ref` al PHPDoc de propiedades. `$guarded = []` ya la cubre para escritura.

- [ ] **Step 5: Crear el servicio**

Crear `src/Privacidad/Bitacora.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\EntradaBitacora;

/**
 * Corta el vínculo entre la bitácora y un titular que se anonimizó.
 *
 * Anonimizar la ficha y dejar intacta la bitácora que apunta a ella es
 * anonimización a medias: el hecho auditable tiene que sobrevivir, el vínculo no.
 */
class Bitacora
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /** @return int cuántas entradas quedaron desvinculadas */
    public function desvincular(Model $titular): int
    {
        return DB::transaction(function () use ($titular): int {
            $ref = (string) Str::ulid();

            // Por query builder a propósito: el modelo es append-only y rechaza
            // `updating`. Cortar el vínculo es la única mutación admitida, y queda
            // registrada abajo con su propia entrada.
            $afectadas = EntradaBitacora::query()
                ->where('titular_type', $titular->getMorphClass())
                ->where('titular_id', $titular->getKey())
                ->update(['titular_id' => null, 'titular_ref' => $ref]);

            if ($afectadas > 0) {
                $this->evidencia->registrar('bitacora.desvinculada', [
                    'entradas' => $afectadas,
                    'titular_ref' => $ref,
                ]);
            }

            return $afectadas;
        });
    }
}
```

- [ ] **Step 6: Llamarlo desde la retención**

En `src/Privacidad/AplicarRetencion.php`, inyectar `Bitacora` en el constructor y, dentro de `aplicarA()`, **después** de `anonimizar()` y **antes** del `registrar('retencion.aplicada', …)`:

```php
        if ($titular instanceof Model) {
            $this->bitacora->desvincular($titular);
        }
```

El orden importa: desvincular antes de escribir la entrada de retención dejaría esa entrada también huérfana, y se pierde la traza de que la retención se aplicó a alguien.

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/BitacoraDesvincularTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): la bitácora se desvincula del titular anonimizado

La referencia es aleatoria y no un hash del id: un hash con la lista de ids se
revierte por fuerza bruta y la anonimización quedaría decorativa."
```

---

### Task 3: La excepción que habilita tratar datos sensibles

La ley prohíbe tratar datos sensibles salvo causales tasadas. `privacidad_finalidades` declara la base de licitud general, pero no la causal que habilita tocar la categoría prohibida.

**Files:**
- Create: `src/Privacidad/ExcepcionDatoSensible.php`
- Create: `database/migrations/2026_08_14_000002_add_excepcion_dato_sensible_a_finalidades.php`
- Modify: `src/Privacidad/Modelos/Finalidad.php`
- Modify: `src/Privacidad/Console/ExportarRatCommand.php`
- Test: `tests/Privacidad/DatoSensibleTest.php`

**Interfaces:**
- Consumes: `Finalidad`, `FinalidadInvalida` (ciclo 1).
- Produces: enum `ExcepcionDatoSensible`; `Finalidad::CATEGORIAS_SENSIBLES`; invariante nueva; el RAT exporta la causal.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/DatoSensibleTest.php`:

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\ExcepcionDatoSensible;
use Muni\Shared\Privacidad\FinalidadInvalida;
use Muni\Shared\Privacidad\Modelos\Finalidad;

function finalidadBase(array $extra = []): array
{
    return array_merge([
        'sistema' => 'discapacidad',
        'codigo' => 'registro_comunal',
        'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
    ], $extra);
}

it('rechaza una finalidad con datos sensibles que no declara la causal', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'categorias_datos' => ['identificacion', 'salud'],
    ])))->toThrow(FinalidadInvalida::class);
});

it('acepta la misma finalidad cuando declara la causal', function () {
    $finalidad = Finalidad::create(finalidadBase([
        'categorias_datos' => ['identificacion', 'salud'],
        'excepcion_dato_sensible' => ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey,
    ]));

    expect($finalidad->exists)->toBeTrue()
        ->and($finalidad->excepcion_dato_sensible)->toBe(ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey);
});

it('no exige causal cuando ninguna categoría es sensible', function () {
    $finalidad = Finalidad::create(finalidadBase([
        'codigo' => 'agenda',
        'categorias_datos' => ['identificacion', 'contacto'],
    ]));

    expect($finalidad->exists)->toBeTrue()
        ->and($finalidad->excepcion_dato_sensible)->toBeNull();
});

it('reconoce como sensible la situación socioeconómica, que es propia de la ley chilena', function () {
    expect(fn () => Finalidad::create(finalidadBase([
        'codigo' => 'ficha_social',
        'categorias_datos' => ['socioeconomico'],
    ])))->toThrow(FinalidadInvalida::class);
});

it('el RAT en json expone la causal', function () {
    config(['privacidad.sistema' => 'discapacidad']);
    Finalidad::create(finalidadBase([
        'categorias_datos' => ['salud'],
        'excepcion_dato_sensible' => ExcepcionDatoSensible::FinesEstatalesHabilitadosPorLey,
    ]));

    $this->artisan('privacidad:rat --json')->assertSuccessful();
    $rat = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['finalidades'][0]['excepcion_dato_sensible'])->toBe('fines_estatales_habilitados_por_ley');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/DatoSensibleTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\ExcepcionDatoSensible" not found`.

- [ ] **Step 3: Crear el enum**

Crear `src/Privacidad/ExcepcionDatoSensible.php`:

```php
<?php

namespace Muni\Shared\Privacidad;

/**
 * Causal que habilita tratar datos sensibles.
 *
 * La ley los prohíbe como regla general y los permite solo por causales tasadas:
 * la base de licitud general NO basta para la categoría prohibida, hay que decir
 * además por qué se puede tocar.
 *
 * REVISIÓN JURÍDICA: este catálogo se propone desde el texto de la ley, pero cuál
 * aplica a cada finalidad municipal es una calificación legal. El código obliga a
 * declarar una; no puede decidir cuál.
 */
enum ExcepcionDatoSensible: string
{
    case ConsentimientoExpreso = 'consentimiento_expreso';
    case FinesEstatalesHabilitadosPorLey = 'fines_estatales_habilitados_por_ley';
    case InteresVital = 'interes_vital';
    case DatosHechosPublicosPorElTitular = 'datos_hechos_publicos_por_el_titular';
    case EjercicioDeDerechosAnteTribunales = 'ejercicio_de_derechos_ante_tribunales';
    case FinesHistoricosEstadisticosCientificos = 'fines_historicos_estadisticos_cientificos';
    case SaludPublicaOSeguridadSocial = 'salud_publica_o_seguridad_social';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ConsentimientoExpreso => 'Consentimiento expreso del titular',
            self::FinesEstatalesHabilitadosPorLey => 'Fines estatales habilitados por ley',
            self::InteresVital => 'Protección de un interés vital',
            self::DatosHechosPublicosPorElTitular => 'Datos hechos públicos por el titular',
            self::EjercicioDeDerechosAnteTribunales => 'Ejercicio de derechos ante tribunales',
            self::FinesHistoricosEstadisticosCientificos => 'Fines históricos, estadísticos o científicos',
            self::SaludPublicaOSeguridadSocial => 'Salud pública o seguridad social',
        };
    }
}
```

- [ ] **Step 4: Crear la migración**

Crear `database/migrations/2026_08_14_000002_add_excepcion_dato_sensible_a_finalidades.php`:

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
            // Nullable porque solo aplica a finalidades que tocan categorías
            // sensibles; la obligatoriedad la impone la invariante del modelo,
            // que sabe qué categorías declaró cada finalidad.
            $table->string('excepcion_dato_sensible')->nullable()->after('norma_habilitante');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_finalidades', function (Blueprint $table): void {
            $table->dropColumn('excepcion_dato_sensible');
        });
    }
};
```

- [ ] **Step 5: Invariante en el modelo**

En `src/Privacidad/Modelos/Finalidad.php`:

```php
    /**
     * Categorías que la ley trata como sensibles. `socioeconomico` está acá
     * porque la ley chilena la incluye explícitamente, a diferencia del RGPD:
     * copiar un catálogo europeo dejaría fuera justo la que más aparece en un
     * municipio.
     */
    public const CATEGORIAS_SENSIBLES = [
        'salud', 'biometricos', 'perfil_biologico', 'origen_etnico',
        'socioeconomico', 'vida_sexual', 'creencias', 'afiliacion',
    ];
```

> **Corregido el 2026-08-15**: esta constante era una lista paralela contra la
> que se hacía `array_intersect` sobre `categorias_datos`, que seguía siendo
> un array de strings libres. Cualquier variante -mayúscula, sinónimo, typo-
> quedaba tratada como no sensible en silencio; `discapacidad` nunca coincidía
> con `salud`. Se reemplazó por el enum backed `CategoriaDato`, que hace el
> catálogo total (rechaza lo no reconocido en vez de ignorarlo) y deriva la
> sensibilidad con `esSensible()` en vez de una lista que podía desalinearse.
> Ver `src/Privacidad/CategoriaDato.php` y el reporte en
> `.superpowers/sdd/2026-08-14-ciclo2-a-fundacion/vocabulario-categorias-report.md`.

Agregar al `$casts`: `'excepcion_dato_sensible' => ExcepcionDatoSensible::class`.

Y como cuarta comprobación de `validarInvariantes()`:

```php
        $sensibles = array_intersect($this->categorias_datos ?? [], self::CATEGORIAS_SENSIBLES);

        if ($sensibles !== [] && $this->excepcion_dato_sensible === null) {
            throw new FinalidadInvalida(
                "La finalidad «{$this->codigo}» trata datos sensibles (".implode(', ', $sensibles).') '
                .'pero no declara la causal que lo habilita. La base de licitud general no basta: '
                .'la ley prohíbe tratarlos salvo causales tasadas.',
            );
        }
```

- [ ] **Step 6: Exponerla en el RAT**

En `src/Privacidad/Console/ExportarRatCommand.php`, agregar al mapeo del JSON:

```php
                    'excepcion_dato_sensible' => $f->excepcion_dato_sensible?->value,
```

Y una columna a la tabla, con `$f->excepcion_dato_sensible?->etiqueta() ?? '—'`.

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/DatoSensibleTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad database/migrations tests/Privacidad
git commit -m "feat(privacidad): declarar la causal que habilita tratar datos sensibles

La base de licitud general no basta: la ley los prohíbe salvo causales tasadas.
El catálogo incluye situación socioeconómica, que es propia de la ley chilena."
```

---

### Task 4: Plazo vigilado de notificación de brechas

Las solicitudes tienen `vence_en` y semáforo; las brechas no tienen ningún plazo calculado. La misma clase de incumplimiento —no avisar a tiempo— queda sin vigilar justo donde el plazo es más corto.

**Files:**
- Create: `database/migrations/2026_08_14_000003_add_vence_notificacion_a_privacidad_brechas.php`
- Modify: `config/privacidad.php`
- Modify: `src/Privacidad/Modelos/Brecha.php`
- Modify: `src/Privacidad/Brechas.php`
- Test: `tests/Privacidad/BrechaPlazoTest.php`

**Interfaces:**
- Consumes: `Brecha`, `Brechas` (ciclo 1).
- Produces: columna `vence_notificacion_agencia_en`; scopes `porVencer(int $dias = 1)` y `vencidas()`; config `privacidad.plazo_notificacion_brecha_dias`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Privacidad/BrechaPlazoTest.php`:

```php
<?php

use Muni\Shared\Privacidad\Brechas;
use Muni\Shared\Privacidad\Modelos\Brecha;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad', 'privacidad.plazo_notificacion_brecha_dias' => 3]);
});

it('calcula el vencimiento desde la detección', function () {
    $this->travelTo('2026-09-01 10:00:00');

    $brecha = app(Brechas::class)->registrar('Acceso indebido', ['riesgo_alto' => true]);

    expect($brecha->vence_notificacion_agencia_en->toDateString())->toBe('2026-09-04');
});

it('respeta la fecha de detección cuando la brecha se registra tarde', function () {
    $this->travelTo('2026-09-10 10:00:00');

    $brecha = app(Brechas::class)->registrar('Detectada antes', [
        'detectada_en' => '2026-09-01 10:00:00',
        'riesgo_alto' => true,
    ]);

    // El reloj corre desde que ocurrió, no desde que alguien la anotó.
    expect($brecha->vence_notificacion_agencia_en->toDateString())->toBe('2026-09-04')
        ->and(Brecha::vencidas()->count())->toBe(1);
});

it('lista las brechas por vencer y las vencidas, sin solaparse', function () {
    $this->travelTo('2026-09-01 10:00:00');
    app(Brechas::class)->registrar('Sin notificar', ['riesgo_alto' => true]);

    $this->travelTo('2026-09-03 10:00:00');
    expect(Brecha::porVencer(2)->count())->toBe(1)
        ->and(Brecha::vencidas()->count())->toBe(0);

    $this->travelTo('2026-09-06 10:00:00');
    expect(Brecha::vencidas()->count())->toBe(1)
        ->and(Brecha::porVencer(2)->count())->toBe(0);
});

it('una brecha ya notificada sale de ambas listas', function () {
    $this->travelTo('2026-09-01 10:00:00');
    $brecha = app(Brechas::class)->registrar('Notificada', ['riesgo_alto' => true]);
    app(Brechas::class)->notificarAgencia($brecha);

    $this->travelTo('2026-09-06 10:00:00');

    expect(Brecha::vencidas()->count())->toBe(0)
        ->and(Brecha::porVencer(2)->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/BrechaPlazoTest.php`
Expected: FAIL — columna `vence_notificacion_agencia_en` inexistente.

- [ ] **Step 3: Configuración**

En `config/privacidad.php`:

```php
    // Plazo para notificar una brecha a la Agencia. Configurable porque debe
    // confirmarse contra el texto vigente y su reglamento antes de producción,
    // igual que el plazo de respuesta a las solicitudes.
    'plazo_notificacion_brecha_dias' => (int) env('PRIVACIDAD_PLAZO_NOTIFICACION_BRECHA_DIAS', 3),
```

- [ ] **Step 4: Migración**

Crear `database/migrations/2026_08_14_000003_add_vence_notificacion_a_privacidad_brechas.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacidad_brechas', function (Blueprint $table): void {
            // Columna y no cálculo: el plazo configurado puede cambiar, y el que
            // corre para una brecha es el que regía cuando se detectó.
            $table->timestamp('vence_notificacion_agencia_en')->nullable()->after('detectada_en');
            $table->index(['notificada_agencia_en', 'vence_notificacion_agencia_en'], 'privacidad_brechas_plazo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('privacidad_brechas', function (Blueprint $table): void {
            $table->dropIndex('privacidad_brechas_plazo_idx');
            $table->dropColumn('vence_notificacion_agencia_en');
        });
    }
};
```

- [ ] **Step 5: Modelo**

En `src/Privacidad/Modelos/Brecha.php`, agregar al `$casts` `'vence_notificacion_agencia_en' => 'datetime'` y los scopes:

```php
    /** @param Builder<Brecha> $query */
    public function scopePorVencer(Builder $query, int $dias = 1): void
    {
        $query->sinNotificar()
            ->whereNotNull('vence_notificacion_agencia_en')
            ->whereBetween('vence_notificacion_agencia_en', [now(), now()->addDays($dias)]);
    }

    /** @param Builder<Brecha> $query */
    public function scopeVencidas(Builder $query): void
    {
        $query->sinNotificar()
            ->whereNotNull('vence_notificacion_agencia_en')
            ->where('vence_notificacion_agencia_en', '<', now());
    }
```

`sinNotificar()` ya existe del ciclo 1 y filtra por `notificada_agencia_en IS NULL`, así que una brecha ya notificada sale de ambas listas sin lógica extra.

- [ ] **Step 6: Calcularlo al registrar**

En `src/Privacidad/Brechas.php`, dentro de `registrar()`, junto a `detectada_en`:

```php
            'vence_notificacion_agencia_en' => Carbon::parse($datos['detectada_en'] ?? now())
                ->addDays((int) config('privacidad.plazo_notificacion_brecha_dias')),
```

El reloj corre desde la detección, no desde el registro: una brecha detectada la semana pasada y anotada hoy ya puede estar vencida, y el sistema tiene que decirlo.

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/BrechaPlazoTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Suite completa y commit**

```bash
vendor/bin/pest && vendor/bin/pint --test
git add src/Privacidad config/privacidad.php database/migrations tests/Privacidad
git commit -m "feat(privacidad): vigilar el plazo de notificación de una brecha

El reloj corre desde la detección, no desde que alguien la anotó: una brecha
registrada tarde puede nacer vencida y el sistema tiene que decirlo."
```

---

## Notas para quien ejecute

- **Si un test del ciclo 1 se cae en la Task 1, es un hallazgo, no un estorbo.** Significa que algo modificaba entradas de bitácora. Reportarlo.
- La Task 2 es la única parte del módulo que muta la bitácora, y lo hace por query builder a propósito. No "arreglar" eso pasándolo por el modelo: el modelo la rechazaría.
- El catálogo de `ExcepcionDatoSensible` está pendiente de revisión jurídica. No presentarlo como definitivo.
- Al terminar el plan la suite debe quedar en **137 tests** (119 + 4 + 5 + 5 + 4). Si no cuadra, falta o sobra algo.
