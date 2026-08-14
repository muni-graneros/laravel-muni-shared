# Adopción del módulo Privacidad (Ley 21.719) en discapacidad-graneros

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que discapacidad-graneros sea el primer sistema que implementa los cinco contratos del módulo `Muni\Shared\Privacidad`, probando el diseño contra un esquema real antes de llevarlo al scaffold.

**Architecture:** El módulo vive en `laravel-muni-shared` (rama `main` local, v1.12 sin publicar). Este sistema aporta lo único que el paquete no puede saber: quién es el titular, qué campos son sensibles, qué campos puede corregir el titular, desde cuándo se trata a cada persona, cómo se acredita identidad, y cómo se propaga una rectificación al maestro.

**Tech Stack:** Laravel 13, Filament 4, Pest, MariaDB/MySQL. El paquete se consume por repositorio `path` local mientras no esté publicado.

**Spec:** `~/Dev/laravel-muni-shared/docs/superpowers/specs/2026-08-13-ley-21719-design.md`
**Pendientes que este plan debe cerrar:** `~/Dev/laravel-muni-shared/docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md`, puntos 1, 2, 4 y 5.

## Global Constraints

- Rama: `feat/privacidad-21719`, salida de `develop`. **Nunca pushear a `main`** (lo bloquea el clasificador); este plan **no pushea nada**.
- Commits en español, sin atribución a IA. El `user.name` local ya está fijado en `buguenocesar92`.
- El paquete se consume con un repositorio `path` apuntando a `~/Dev/laravel-muni-shared`. **Ese cambio en `composer.json` es temporal y debe quedar aislado en su propio commit**, para poder revertirlo cuando el paquete se publique.
- No modificar nada dentro de `vendor/`.
- Tests con Pest. Los del módulo son Feature, no Unit: tocan base de datos.
- `make test` usa la BD `sistema_test`; no apunta a la BD de desarrollo.
- Cada tarea termina con la suite del repo en verde y Pint limpio.

## El hallazgo que condiciona todo el plan

El esquema real de `personas` rompe una anonimización ingenua:

```
nro_documento       VARCHAR  NOT NULL  UNIQUE
nro_documento_norm  VARCHAR  GENERATED ALWAYS AS (...) STORED  + índice
nombres, apellidos  VARCHAR  NOT NULL
```

- `anonimizar()` **no puede** dejar `nro_documento` en null (NOT NULL) ni escribir un valor fijo (UNIQUE).
- `nro_documento_norm` es columna generada: se recalcula sola y no admite escritura directa.

Esto es exactamente lo que el review final del paquete marcó como no verificable desde afuera. Es la razón por la que `anonimizar()` es un método del contrato y no lógica genérica del paquete.

---

### Task 1: Consumir el paquete localmente y verificar que migra

**Files:**
- Modify: `composer.json`
- Test: verificación manual documentada en el reporte

**Interfaces:**
- Consumes: nada.
- Produces: `Muni\Shared\Privacidad\*` disponible en el autoload de este proyecto; seis tablas `privacidad_*` creadas.

- [ ] **Step 1: Agregar el repositorio path**

En `composer.json`, dentro de `repositories`, **antes** de la entrada `muni-shared` (Composer respeta el orden y el primero que resuelve gana):

```json
"muni-shared-local": {
    "type": "path",
    "url": "../laravel-muni-shared",
    "options": { "symlink": true }
}
```

Y cambiar la restricción de versión a un alias de rama:

```json
"muni-graneros/laravel-muni-shared": "dev-main as 1.12.0"
```

**Por qué el alias y no `^1.12`:** el `composer.json` del paquete no declara un
campo `version`, así que un repositorio `path` lo publica como `dev-main` (el
nombre de la rama) y **`^1.12` no calzaría nunca**. El `as 1.12.0` deja además
escrito qué versión se está probando, para cuando se revierta a la publicada.

- [ ] **Step 2: Instalar**

Run: `composer update muni-graneros/laravel-muni-shared --no-interaction`
Expected: Composer reporta `Symlinking` desde `../laravel-muni-shared`.

Verificar que llegó el módulo, no solo el paquete:

```bash
ls vendor/muni-graneros/laravel-muni-shared/src/Privacidad/
```

Expected: `Contratos/`, `Modelos/`, `Console/`, `AplicarRetencion.php`, `Consentimientos.php`, `Solicitudes.php`, `Rectificaciones.php`, `Brechas.php`, `ExportacionDeDatos.php`.

Si el directorio no existe, `main` de `laravel-muni-shared` no tiene el módulo:
`git -C ../laravel-muni-shared log --oneline -1` debe mostrar el merge.

- [ ] **Step 3: Migrar contra la base de pruebas y verificar las seis tablas**

Run: `php artisan migrate --database=mysql --env=testing` (o el comando equivalente del Makefile del repo)
Expected: crea `privacidad_finalidades`, `privacidad_bitacora`, `privacidad_consentimientos`, `privacidad_solicitudes`, `privacidad_brechas`, y la columna `vigente_clave`.

**Esto es lo primero que puede fallar de verdad**: las migraciones del paquete se probaron solo en SQLite. Si MySQL rechaza algo (longitud de índice, tipo de columna generada, `json` en MariaDB), es un hallazgo del paquete y hay que reportarlo, no parchearlo acá.

- [ ] **Step 4: Configurar el sistema**

Agregar a `.env` y a `.env.example`:

```
PRIVACIDAD_SISTEMA=discapacidad
PRIVACIDAD_PLAZO_RESPUESTA_DIAS=30
PRIVACIDAD_RESPONSABLE="I. Municipalidad de Graneros"
PRIVACIDAD_CONTACTO=
PRIVACIDAD_DELEGADO=
```

`PRIVACIDAD_SISTEMA` ya no tiene valor por defecto en el paquete: sin él, el RAT sale vacío y la retención no encuentra finalidades.

- [ ] **Step 5: Commit aislado**

```bash
git add composer.json composer.lock .env.example
git commit -m "chore(privacidad): consumir el módulo desde el paquete local

El repositorio path es temporal: se revierte cuando laravel-muni-shared
publique la v1.12."
```

---

### Task 2: `TitularDeDatos` en `Persona`

**Files:**
- Modify: `app/Models/Persona.php`
- Test: `tests/Feature/Privacidad/TitularDeDatosTest.php`

**Interfaces:**
- Consumes: `Muni\Shared\Privacidad\Contratos\TitularDeDatos`.
- Produces: `Persona implements TitularDeDatos` con los seis métodos.

- [ ] **Step 1: Write the failing test**

Crear `tests/Feature/Privacidad/TitularDeDatosTest.php`:

```php
<?php

use App\Models\Persona;
use App\Models\PersonaDiscapacidad;
use App\Models\TipoDiscapacidad;
use App\Models\TipoDocumento;
use Illuminate\Support\Facades\Storage;

function personaDePrueba(): Persona
{
    return Persona::create([
        'tipo_documento_id' => TipoDocumento::first()->id,
        'nro_documento' => '11.111.111-1',
        'nombres' => 'Rocío',
        'apellidos' => 'Paredes',
        'fecha_nacimiento' => '1980-06-15',
        'sexo' => 'femenino',
        'telefono' => '+56911111111',
        'email' => 'rocio@example.cl',
        'direccion' => 'Calle Falsa 123',
        'sector' => 'Centro',
    ]);
}

it('exporta los datos personales para acceso y portabilidad', function () {
    $datos = personaDePrueba()->exportarDatosPersonales();

    expect($datos)->toHaveKeys(['identificacion', 'contacto', 'discapacidades'])
        ->and($datos['identificacion']['nombres'])->toBe('Rocío');
});

it('declara qué campos puede corregir el titular, y el documento no es uno', function () {
    $campos = personaDePrueba()->camposRectificables();

    expect($campos)->toContain('nombres', 'apellidos', 'telefono', 'direccion')
        ->and($campos)->not->toContain('nro_documento');
});

it('purga los datos sensibles: descripción de la discapacidad y archivo de consentimiento', function () {
    Storage::fake('sensibles');
    $persona = personaDePrueba();
    Storage::disk('sensibles')->put('consentimientos/firma.png', 'contenido');
    $persona->update(['consentimiento_path' => 'consentimientos/firma.png']);
    PersonaDiscapacidad::create([
        'persona_id' => $persona->id,
        'tipo_discapacidad_id' => TipoDiscapacidad::first()->id,
        'descripcion' => 'diagnóstico clínico detallado',
    ]);

    $persona->purgarDatosSensibles();

    expect(PersonaDiscapacidad::where('persona_id', $persona->id)->first()->descripcion)->toBeNull()
        ->and($persona->fresh()->consentimiento_path)->toBeNull();
    Storage::disk('sensibles')->assertMissing('consentimientos/firma.png');
});

it('anonimiza sin violar NOT NULL ni la unicidad del documento', function () {
    $a = personaDePrueba();
    $b = Persona::create([
        'tipo_documento_id' => TipoDocumento::first()->id,
        'nro_documento' => '22.222.222-2',
        'nombres' => 'Otro', 'apellidos' => 'Titular',
    ]);

    $a->anonimizar();
    $b->anonimizar();

    $a->refresh();
    expect($a->nro_documento)->not->toBeNull()
        ->and($a->nro_documento)->not->toContain('11.111.111')
        ->and($a->nombres)->toBe('ANONIMIZADO')
        ->and($a->telefono)->toBeNull()
        ->and($a->email)->toBeNull()
        ->and($a->direccion)->toBeNull()
        // Se conserva lo que sirve para estadística comunal.
        ->and($a->sexo)->toBe('femenino')
        ->and($a->sector)->toBe('Centro')
        // La fecha queda al 1 de enero: preserva el tramo etario, borra el día exacto.
        ->and($a->fecha_nacimiento->format('Y-m-d'))->toBe('1980-01-01')
        // Y dos anonimizados no colisionan en el índice único.
        ->and($a->nro_documento)->not->toBe($b->refresh()->nro_documento);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Privacidad/TitularDeDatosTest.php`
Expected: FAIL — `Call to undefined method App\Models\Persona::exportarDatosPersonales()`.

- [ ] **Step 3: Implementar el contrato**

En `app/Models/Persona.php`, agregar `implements TitularDeDatos` (con su `use`) y estos métodos:

```php
    public function titularNombre(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    public function titularDocumento(): string
    {
        return (string) $this->nro_documento;
    }

    /** @return array<string, mixed> */
    public function exportarDatosPersonales(): array
    {
        return [
            'identificacion' => [
                'documento' => $this->nro_documento,
                'nombres' => $this->nombres,
                'apellidos' => $this->apellidos,
                'fecha_nacimiento' => $this->fecha_nacimiento?->format('Y-m-d'),
                'sexo' => $this->sexo,
            ],
            'contacto' => [
                'telefono' => $this->telefono,
                'email' => $this->email,
                'direccion' => $this->direccion,
                'sector' => $this->sector,
            ],
            'discapacidades' => $this->discapacidades()->get()
                ->map(fn ($d) => [
                    'tipo' => $d->tipoDiscapacidad->nombre ?? null,
                    'credencial_senadis' => $d->credencial_senadis,
                    'fecha_registro' => $d->fecha_registro?->format('Y-m-d'),
                ])->all(),
        ];
    }

    /**
     * El derecho de rectificación cubre el dato inexacto del titular, no todo el
     * registro. Quedan fuera a propósito: `nro_documento`, porque cambiar el RUT
     * no es una rectificación sino un cambio de identidad que acredita el
     * Registro Civil; y los datos de discapacidad, que dependen de un
     * certificado médico y no de la declaración del titular.
     *
     * @return array<int, string>
     */
    public function camposRectificables(): array
    {
        return ['nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'email', 'direccion', 'sector'];
    }

    public function purgarDatosSensibles(): void
    {
        // La descripción va cifrada en reposo, pero cifrado no es supresión:
        // la ley pide que el dato deje de existir, no que esté guardado a salvo.
        $this->discapacidades()->update(['descripcion' => null]);

        if ($this->consentimiento_path && Storage::disk('sensibles')->exists($this->consentimiento_path)) {
            Storage::disk('sensibles')->delete($this->consentimiento_path);
        }

        $this->forceFill(['consentimiento_path' => null])->save();
    }

    /**
     * `nro_documento` es NOT NULL y UNIQUE, y `nro_documento_norm` es una columna
     * generada que se recalcula sola desde él: no se puede anular ni repetir un
     * valor fijo. Por eso el documento se sustituye por un centinela único por id.
     *
     * Se conservan `sexo`, `sector` y el AÑO de nacimiento porque son lo que
     * sostiene la estadística comunal de discapacidad. Queda dicho para quien
     * revise esto: en una comuna chica, sector + tipo de discapacidad + año de
     * nacimiento puede volver a identificar a alguien. Reducir eso más (agrupar
     * por tramos, o soltar el sector) es una decisión del municipio, no técnica.
     */
    public function anonimizar(): void
    {
        $this->forceFill([
            'nro_documento' => 'ANON-'.$this->getKey(),
            'nombres' => 'ANONIMIZADO',
            'apellidos' => 'ANONIMIZADO',
            'telefono' => null,
            'email' => null,
            'direccion' => null,
            'latitud' => null,
            'longitud' => null,
            'tutor_id' => null,
            'parentesco_id' => null,
            'fecha_nacimiento' => $this->fecha_nacimiento?->startOfYear(),
        ])->save();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Privacidad/TitularDeDatosTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Suite completa y estilo**

Run: `make test && vendor/bin/pint --test`
Expected: verde. `Persona` es un modelo central: si algo se rompe, es real.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Persona.php tests/Feature/Privacidad
git commit -m "feat(privacidad): Persona es titular de datos

anonimizar() sustituye el documento por un centinela único en vez de anularlo:
la columna es NOT NULL, UNIQUE y alimenta una columna generada."
```

---

### Task 3: El RAT de discapacidad

**Files:**
- Create: `database/seeders/FinalidadesPrivacidadSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Privacidad/RatTest.php`

**Interfaces:**
- Consumes: `Muni\Shared\Privacidad\Modelos\Finalidad`, `BaseLicitud`.
- Produces: las finalidades declaradas del sistema `discapacidad`.

> **REVISIÓN HUMANA OBLIGATORIA.** Este seeder es una declaración jurídica: dice
> qué trata este sistema, con qué base legal y por cuánto tiempo. Los códigos y
> las bases se deducen del código existente, pero **los plazos de retención y las
> normas habilitantes exactas los tiene que confirmar el municipio.** Se
> implementan con los valores propuestos y quedan marcados para revisión.

- [ ] **Step 1: Write the failing test**

Crear `tests/Feature/Privacidad/RatTest.php`:

```php
<?php

use Database\Seeders\FinalidadesPrivacidadSeeder;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(fn () => $this->seed(FinalidadesPrivacidadSeeder::class));

it('declara las finalidades del sistema con su base de licitud', function () {
    $finalidades = Finalidad::delSistema('discapacidad')->get();

    expect($finalidades)->not->toBeEmpty()
        ->and($finalidades->pluck('codigo'))->toContain('registro_comunal', 'atencion_social');
});

it('el registro base se funda en la ley y cita la norma', function () {
    $registro = Finalidad::delSistema('discapacidad')->where('codigo', 'registro_comunal')->sole();

    expect($registro->base_licitud)->toBe(BaseLicitud::FuncionLegal)
        ->and($registro->norma_habilitante)->not->toBeEmpty()
        ->and($registro->es_accesoria)->toBeFalse();
});

it('toda finalidad accesoria se funda en el consentimiento y es revocable', function () {
    Finalidad::delSistema('discapacidad')->accesorias()->get()
        ->each(fn ($f) => expect($f->base_licitud)->toBe(BaseLicitud::Consentimiento));
});

it('marca como sensibles las finalidades que tratan datos de salud', function () {
    $registro = Finalidad::delSistema('discapacidad')->where('codigo', 'registro_comunal')->sole();

    expect($registro->categorias_datos)->toContain('salud');
});

it('el seeder es idempotente', function () {
    $antes = Finalidad::delSistema('discapacidad')->count();
    $this->seed(FinalidadesPrivacidadSeeder::class);

    expect(Finalidad::delSistema('discapacidad')->count())->toBe($antes);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Privacidad/RatTest.php`
Expected: FAIL — clase `FinalidadesPrivacidadSeeder` no encontrada.

- [ ] **Step 3: Escribir el seeder**

Crear `database/seeders/FinalidadesPrivacidadSeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * El registro de actividades de tratamiento (RAT) de este sistema.
 *
 * REVISAR CON EL MUNICIPIO antes de dar por buenos los plazos de retención y
 * las normas habilitantes: son una declaración jurídica, no una decisión
 * técnica. Los valores de acá son una propuesta deducida del sistema.
 */
class FinalidadesPrivacidadSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->finalidades() as $finalidad) {
            Finalidad::updateOrCreate(
                ['sistema' => 'discapacidad', 'codigo' => $finalidad['codigo']],
                $finalidad,
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function finalidades(): array
    {
        return [
            [
                'codigo' => 'registro_comunal',
                'nombre' => 'Registro comunal de personas con discapacidad',
                'descripcion' => 'Inscripción y caracterización de las personas con discapacidad de la comuna para el diseño y la ejecución de los programas municipales de inclusión.',
                'base_licitud' => BaseLicitud::FuncionLegal,
                'norma_habilitante' => 'Ley 20.422, arts. 1 y 8; LOC de Municipalidades, art. 4 letra c)',
                'es_accesoria' => false,
                'plazo_retencion_meses' => 120,
                'categorias_datos' => ['identificacion', 'contacto', 'salud', 'socioeconomico'],
                'destinatarios' => ['maestro_personas'],
            ],
            [
                'codigo' => 'atencion_social',
                'nombre' => 'Atenciones y seguimiento de casos',
                'descripcion' => 'Registro de las atenciones prestadas y su seguimiento.',
                'base_licitud' => BaseLicitud::FuncionLegal,
                'norma_habilitante' => 'Ley 20.422, art. 8; LOC de Municipalidades, art. 4 letra c)',
                'es_accesoria' => false,
                'plazo_retencion_meses' => 60,
                'categorias_datos' => ['identificacion', 'salud'],
                'destinatarios' => [],
            ],
            [
                'codigo' => 'ayudas_tecnicas',
                'nombre' => 'Entrega de ayudas técnicas',
                'descripcion' => 'Gestión y entrega de ayudas técnicas a las personas inscritas.',
                'base_licitud' => BaseLicitud::FuncionLegal,
                'norma_habilitante' => 'Ley 20.422, art. 8',
                'es_accesoria' => false,
                'plazo_retencion_meses' => 60,
                'categorias_datos' => ['identificacion', 'salud'],
                'destinatarios' => [],
            ],
            [
                'codigo' => 'agenda_citas',
                'nombre' => 'Agendamiento de citas',
                'descripcion' => 'Programación y control de asistencia a citas.',
                'base_licitud' => BaseLicitud::FuncionLegal,
                'norma_habilitante' => 'LOC de Municipalidades, art. 4 letra c)',
                'es_accesoria' => false,
                'plazo_retencion_meses' => 24,
                'categorias_datos' => ['identificacion', 'contacto'],
                'destinatarios' => [],
            ],
            [
                'codigo' => 'sincronizacion_maestro',
                'nombre' => 'Sincronización con el maestro de personas',
                'descripcion' => 'Comunicación de los datos identificatorios al registro único de personas del ecosistema municipal, para no pedirle al vecino lo mismo en cada oficina.',
                'base_licitud' => BaseLicitud::FuncionLegal,
                'norma_habilitante' => 'LOC de Municipalidades, art. 4 letra c)',
                'es_accesoria' => false,
                'plazo_retencion_meses' => null,
                'categorias_datos' => ['identificacion', 'contacto'],
                'destinatarios' => ['maestro_personas'],
            ],
            [
                'codigo' => 'comunicaciones',
                'nombre' => 'Comunicaciones y difusión de beneficios',
                'descripcion' => 'Envío de avisos sobre beneficios, operativos y actividades. Separable del servicio: negarse no afecta la inscripción ni la atención.',
                'base_licitud' => BaseLicitud::Consentimiento,
                'norma_habilitante' => null,
                'es_accesoria' => true,
                'plazo_retencion_meses' => 24,
                'categorias_datos' => ['identificacion', 'contacto'],
                'destinatarios' => [],
            ],
        ];
    }
}
```

- [ ] **Step 4: Registrar el seeder**

En `database/seeders/DatabaseSeeder.php`, agregar `FinalidadesPrivacidadSeeder::class` a la llamada `$this->call([...])` existente. No reordenar lo que ya está.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Privacidad/RatTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Suite y estilo, luego commit**

```bash
make test && vendor/bin/pint --test
git add database/seeders tests/Feature/Privacidad
git commit -m "feat(privacidad): declarar el RAT del sistema de discapacidad

Seis finalidades con su base de licitud. Los plazos de retención y las normas
habilitantes son una propuesta: los confirma el municipio."
```

---

### Task 4: Resolver titulares vencidos y acreditar identidad

**Files:**
- Create: `app/Privacidad/TitularesVencidosPorAtencion.php`
- Create: `app/Privacidad/VerificacionPresencialCedula.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Privacidad/RetencionYVerificacionTest.php`

**Interfaces:**
- Consumes: `ResuelveTitularesVencidos`, `VerificadorIdentidad`, `ResultadoVerificacion`.
- Produces: ambos contratos enlazados en el contenedor.

- [ ] **Step 1: Write the failing test**

Crear `tests/Feature/Privacidad/RetencionYVerificacionTest.php`:

```php
<?php

use App\Models\Persona;
use App\Models\TipoDocumento;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\VerificadorIdentidad;
use Muni\Shared\Privacidad\Modelos\Finalidad;

function personaConRegistro(string $doc, string $fecha): Persona
{
    return Persona::create([
        'tipo_documento_id' => TipoDocumento::first()->id,
        'nro_documento' => $doc,
        'nombres' => 'Titular', 'apellidos' => 'De Prueba',
        'fecha_registro' => $fecha,
    ]);
}

it('considera vencido a quien no tiene actividad dentro del plazo de la finalidad', function () {
    $finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'atencion_social', 'nombre' => 'Atenciones',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 60,
    ]);
    $vencida = personaConRegistro('11.111.111-1', now()->subYears(6)->toDateString());
    personaConRegistro('22.222.222-2', now()->subMonths(3)->toDateString());

    $vencidos = collect(app(ResuelveTitularesVencidos::class)->vencidos($finalidad));

    expect($vencidos->pluck('id')->all())->toBe([$vencida->id]);
});

it('acredita identidad cuando el RUT presentado coincide con el del titular', function () {
    $persona = personaConRegistro('11.111.111-1', now()->toDateString());

    $r = app(VerificadorIdentidad::class)->verificar([
        'titular' => $persona,
        'run' => '11111111-1',
    ]);

    expect($r->verificado)->toBeTrue()
        ->and($r->metodo)->toBe('cedula_presencial');
});

it('rechaza cuando el RUT presentado no es el del titular', function () {
    $persona = personaConRegistro('11.111.111-1', now()->toDateString());

    $r = app(VerificadorIdentidad::class)->verificar([
        'titular' => $persona,
        'run' => '22222222-2',
    ]);

    expect($r->verificado)->toBeFalse();
});

it('rechaza un RUT con dígito verificador inválido, aunque coincida el número', function () {
    $persona = personaConRegistro('11.111.111-1', now()->toDateString());

    $r = app(VerificadorIdentidad::class)->verificar([
        'titular' => $persona,
        'run' => '11111111-9',
    ]);

    expect($r->verificado)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Privacidad/RetencionYVerificacionTest.php`
Expected: FAIL — el contenedor resuelve `NingunTitularVencido` y el primer test devuelve vacío; `VerificadorIdentidad` no está enlazado.

- [ ] **Step 3: Resolver titulares vencidos**

Crear `app/Privacidad/TitularesVencidosPorAtencion.php`:

```php
<?php

namespace App\Privacidad;

use App\Models\Persona;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * Cuándo deja de ser necesario tratar a una persona, en este sistema.
 *
 * El reloj no corre desde la inscripción sino desde la última señal de vida del
 * caso: una atención o una cita reactivan el vínculo, y anonimizar a alguien que
 * volvió el mes pasado sería un error caro de revertir.
 */
class TitularesVencidosPorAtencion implements ResuelveTitularesVencidos
{
    /** @return iterable<int, Persona> */
    public function vencidos(Finalidad $finalidad): iterable
    {
        $corte = now()->subMonths((int) $finalidad->plazo_retencion_meses);

        return Persona::query()
            ->whereNotNull('fecha_registro')
            ->where('fecha_registro', '<', $corte)
            ->whereDoesntHave('atenciones', fn ($q) => $q->where('created_at', '>=', $corte))
            ->whereDoesntHave('citas', fn ($q) => $q->where('created_at', '>=', $corte))
            // Ya anonimizada: no hay nada más que hacerle.
            ->where('nro_documento', 'not like', 'ANON-%')
            ->cursor();
    }
}
```

Las relaciones están verificadas y existen en `app/Models/Persona.php`: `discapacidades()`, `atenciones()`, `citas()`, `tutor()`, `parentesco()`.

- [ ] **Step 4: Verificar identidad**

Crear `app/Privacidad/VerificacionPresencialCedula.php`:

```php
<?php

namespace App\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\VerificadorIdentidad;
use Muni\Shared\Privacidad\ResultadoVerificacion;
use Muni\Shared\RutHelper;

/**
 * Acreditación presencial: el funcionario tiene la cédula del titular delante.
 *
 * No hay cuentas de ciudadano en este sistema, y no debería haberlas para esto:
 * un portal que entregue datos de salud a quien escriba un RUT es una fuga
 * garantizada. La cédula en el mesón es una acreditación más fuerte que una
 * contraseña, y lo que el módulo necesita registrar es CÓMO se acreditó.
 */
class VerificacionPresencialCedula implements VerificadorIdentidad
{
    /** @param array<string, mixed> $contexto */
    public function verificar(array $contexto): ResultadoVerificacion
    {
        $titular = $contexto['titular'] ?? null;
        $run = (string) ($contexto['run'] ?? '');

        if (! $titular instanceof Model || $run === '') {
            return ResultadoVerificacion::fallida('cedula_presencial', 'faltan el titular o el RUN leído de la cédula');
        }

        if (! RutHelper::validate($run)) {
            return ResultadoVerificacion::fallida('cedula_presencial', 'el RUN presentado no es válido');
        }

        // Ambos lados pasan por RutHelper::normalize. NO comparar contra
        // `personas.nro_documento_norm`: esa columna generada normaliza distinto
        // (quita el guión, "111111111"), mientras normalize() lo conserva
        // ("11111111-1"). Mezclarlas hace que nunca calce y el rechazo sea mudo.
        if (RutHelper::normalize($run) !== RutHelper::normalize((string) $titular->nro_documento)) {
            return ResultadoVerificacion::fallida('cedula_presencial', 'el RUN presentado no corresponde al titular');
        }

        // Nunca el RUN en claro: la evidencia queda en la bitácora, que no está cifrada.
        return new ResultadoVerificacion(true, 'cedula_presencial', [
            'run_hash' => hash('sha256', RutHelper::normalize($run)),
            'funcionario_id' => $contexto['funcionario_id'] ?? null,
        ]);
    }
}
```

La API de `Muni\Shared\RutHelper` está verificada: `clean()`, `normalize()`, `format()`, `validate()`, `calcularDv()`. No existe `esValido` ni `normalizar`.

- [ ] **Step 5: Enlazar en el provider**

En `app/Providers/AppServiceProvider.php`, dentro de `register()`:

```php
        $this->app->bind(
            \Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos::class,
            \App\Privacidad\TitularesVencidosPorAtencion::class,
        );

        $this->app->bind(
            \Muni\Shared\Privacidad\Contratos\VerificadorIdentidad::class,
            \App\Privacidad\VerificacionPresencialCedula::class,
        );
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Privacidad/RetencionYVerificacionTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Suite, estilo y commit**

```bash
make test && vendor/bin/pint --test
git add app/Privacidad app/Providers/AppServiceProvider.php tests/Feature/Privacidad
git commit -m "feat(privacidad): resolver titulares vencidos y acreditar identidad en el mesón

El reloj de la retención corre desde la última atención, no desde la
inscripción: quien volvió el mes pasado no está vencido."
```

---

### Task 5: Propagar la rectificación al maestro, de forma síncrona

**Files:**
- Create: `app/Privacidad/PropagaRectificacionAlMaestro.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Privacidad/RectificacionTest.php`

**Interfaces:**
- Consumes: `PropagaRectificacion`, `App\Jobs\SincronizarPersonaAlMaestro`.
- Produces: el contrato enlazado.

> **El contrato exige que sea SÍNCRONO.** `SincronizarPersonaAlMaestro` es
> `ShouldQueue`: despacharlo y devolver `true` reportaría éxito antes de que el
> maestro haya visto nada, y anularía la garantía central del diseño con todos
> los tests en verde. Se invoca su `handle()` directamente.

- [ ] **Step 1: Write the failing test**

Crear `tests/Feature/Privacidad/RectificacionTest.php`:

```php
<?php

use App\Models\Persona;
use App\Models\TipoDocumento;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;

beforeEach(function () {
    config(['services.personas_api.url' => 'http://personas-api:8000', 'services.personas_api.token' => 'tok']);
    $this->persona = Persona::create([
        'tipo_documento_id' => TipoDocumento::first()->id,
        'nro_documento' => '11.111.111-1',
        'nombres' => 'Rocio', 'apellidos' => 'Paredez',
    ]);
});

it('propaga la rectificación al maestro sin pasar por la cola', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $ok = app(PropagaRectificacion::class)->propagar($this->persona, ['apellidos' => 'Paredes']);

    expect($ok)->toBeTrue();
    Http::assertSentCount(1);
    // Si esto falla, la implementación despachó en cola: reportaría éxito antes
    // de que el maestro viera nada.
    Queue::assertNothingPushed();
});

it('deja que el fallo del maestro se propague en vez de devolver un falso true', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(fn () => app(PropagaRectificacion::class)->propagar($this->persona, ['apellidos' => 'Paredes']))
        ->toThrow(Throwable::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Privacidad/RectificacionTest.php`
Expected: FAIL — `Target [PropagaRectificacion] is not instantiable`.

- [ ] **Step 3: Implementar**

Crear `app/Privacidad/PropagaRectificacionAlMaestro.php`:

```php
<?php

namespace App\Privacidad;

use App\Jobs\SincronizarPersonaAlMaestro;
use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\PropagaRectificacion;

/**
 * Empuja al maestro una rectificación ya aplicada localmente.
 *
 * Se ejecuta el job EN LÍNEA, no en cola: el contrato exige saber si el maestro
 * aceptó antes de sellar la solicitud como resuelta. Despacharlo y devolver
 * true reportaría éxito antes de que el maestro viera nada, y el municipio
 * habría certificado por escrito una corrección que la siguiente sincronización
 * puede pisar.
 *
 * El transporte lanza ante cualquier respuesta no-2xx; el módulo trata esa
 * excepción como "no propagado" y revierte la rectificación completa.
 */
class PropagaRectificacionAlMaestro implements PropagaRectificacion
{
    /** @param array<string, mixed> $cambios */
    public function propagar(Model $titular, array $cambios): bool
    {
        (new SincronizarPersonaAlMaestro($titular->getKey()))->handle();

        return true;
    }
}
```

- [ ] **Step 4: Enlazar en el provider**

En `register()` de `AppServiceProvider`, junto a los enlaces de la Task 4:

```php
        $this->app->bind(
            \Muni\Shared\Privacidad\Contratos\PropagaRectificacion::class,
            \App\Privacidad\PropagaRectificacionAlMaestro::class,
        );
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Privacidad/RectificacionTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Suite, estilo y commit**

```bash
make test && vendor/bin/pint --test
git add app/Privacidad app/Providers/AppServiceProvider.php tests/Feature/Privacidad
git commit -m "feat(privacidad): la rectificación se propaga al maestro en línea

En cola devolvería true antes de que el maestro viera nada, y el municipio
habría certificado una corrección que la siguiente sincronización pisa."
```

---

### Task 6: Verificación en vivo contra el sistema real

Esto es lo que hace que el plan valga: los contratos ejercitados contra el esquema y los datos reales, no contra un fixture.

**Files:**
- Create: `~/Dev/laravel-muni-shared/docs/privacidad/verificacion-adopcion-discapacidad.md`

> La evidencia va en el repo del **paquete**, no en el de discapacidad: ese repo
> ignora `docs/*` por decisión explícita (roadmaps y esquema son material de
> reconocimiento y se quedan fuera). No forzar con `git add -f`.

- [ ] **Step 1: Levantar el sistema**

Run: el comando del Makefile que levanta los contenedores de este repo (`make up` o equivalente; verificar en el `Makefile`).

- [ ] **Step 2: Sembrar el RAT y exportarlo**

```bash
php artisan db:seed --class=Database\\Seeders\\FinalidadesPrivacidadSeeder
php artisan privacidad:rat
php artisan privacidad:rat --json
```

Expected: la tabla lista las seis finalidades con su base de licitud, su norma y su estado; el JSON parsea y trae `responsable.nombre` con el valor del `.env`.

- [ ] **Step 3: Retención en simulación, contra datos reales**

```bash
php artisan privacidad:aplicar-retencion
```

Expected: informa qué finalidades tienen titulares vencidos y **no modifica nada**. Verificar en la base que ninguna fila cambió.

**No correr `--ejecutar` contra datos reales.** Si se quiere probar el camino destructivo, hacerlo contra la base de pruebas con datos sembrados.

- [ ] **Step 4: Registrar lo que se encontró**

Crear `~/Dev/laravel-muni-shared/docs/privacidad/verificacion-adopcion-discapacidad.md` con la salida real de los tres comandos y, sobre todo, **con lo que haya fallado**. Esa lista es el insumo del Plan 3 para los otros siete sistemas: cada cosa que se rompió acá se va a romper allá.

- [ ] **Step 5: Commit**

```bash
git add docs/privacidad
git commit -m "docs(privacidad): evidencia de la adopción en el sistema real"
```

---

## Notas para quien ejecute

- **Si una migración del paquete falla en MySQL, es un hallazgo del paquete.** Se probaron solo contra SQLite. Reportarlo, no parchear el esquema acá.
- **No pushear nada.** Ni esta rama ni el paquete.
- Las relaciones y la API de `RutHelper` que usa este plan están verificadas contra el código; no hay que adivinarlas.
- **Trampa de normalización de RUT:** `RutHelper::normalize()` devuelve `11111111-1` (con guión) y la columna generada `personas.nro_documento_norm` produce `111111111` (sin guión). Nunca comparar una contra la otra: no calzan nunca y el fallo es mudo.
- El seeder de finalidades queda pendiente de revisión del municipio. No presentarlo como definitivo.
