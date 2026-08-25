# Ciclo 0 — El núcleo del ciclo ARCOP

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sacar de `SolicitudResource` (laravel-muni-ui) las reglas con consecuencia legal y dejarlas en `Muni\Shared\Privacidad\Ciclo`, para que el panel de Filament y el futuro panel Blade sean dos vistas del mismo núcleo.

**Architecture:** Cada regla se escribe primero en `laravel-muni-shared` con su test (Pest + Testbench), y recién después `laravel-muni-ui` la delega. Ninguna clase del núcleo devuelve presentación: entrega enums y objetos de hechos, y cada panel elige color y redacción. El criterio de éxito es que la suite de `discapacidad-graneros` siga verde **sin tocar disc**.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Pest 3/4, Orchestra Testbench, Laravel Pint, PHPStan.

**Spec:** `docs/superpowers/specs/2026-08-24-arcop-panel-blade-design.md`

## Global Constraints

- Repositorio de las tareas 1–9: `/home/cesar/Dev/laravel-muni-shared`. Tarea 10: `/home/cesar/Dev/laravel-muni-ui`.
- Namespace nuevo: `Muni\Shared\Privacidad\Ciclo`. Tests en `tests/Privacidad/Ciclo/`.
- Los tests del paquete extienden `Muni\Shared\Tests\TestCase` automáticamente (`tests/Pest.php` aplica `uses()` a todo el directorio). No declarar `uses()` por archivo.
- El titular de prueba es `Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba`.
- **Ninguna clase del núcleo llama a `auth()`, `config()` de presentación, ni devuelve colores.** Lo que necesite saber quién está actuando, lo recibe por parámetro.
- Comentarios y mensajes en español; nombres de clases, métodos y commits en inglés solo donde ya lo estén — este paquete nombra en español y se sigue esa convención.
- Commits sin ninguna atribución a IA. Autor: `buguenocesar92 <buguenocesar92@gmail.com>`.
- Antes de cada commit: `vendor/bin/pint` y `composer test` en verde.

---

### Task 1: `EstadoDePlazo` y `PlazoLegal`

El semáforo de plazo hoy vive en `SolicitudResource::etiquetaPlazo()` y devuelve un string en español que otro `match` traduce a un color de Filament. El núcleo devuelve un enum; el color lo elige cada panel.

**Files:**
- Create: `src/Privacidad/Ciclo/EstadoDePlazo.php`
- Create: `src/Privacidad/Ciclo/PlazoLegal.php`
- Modify: `src/Privacidad/Modelos/Solicitud.php:73-79` (que `scopePorVencer()` use la constante en vez de repetir el 5)
- Test: `tests/Privacidad/Ciclo/PlazoLegalTest.php`

**Interfaces:**
- Consumes: `Muni\Shared\Privacidad\Modelos\Solicitud` (`diasRestantes(): int`, `estado: EstadoDeSolicitud`), `EstadoDeSolicitud::estaResuelta(): bool`.
- Produces: `EstadoDePlazo` (casos `Resuelta`, `Vencida`, `PorVencer`, `EnPlazo`; método `etiqueta(): string`), `PlazoLegal::DIAS_POR_VENCER` (int, 5), `PlazoLegal::de(Solicitud $solicitud): EstadoDePlazo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Ciclo\EstadoDePlazo;
use Muni\Shared\Privacidad\Ciclo\PlazoLegal;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'atencion', 'nombre' => 'Atenciones',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);

    $this->solicitud = function (EstadoDeSolicitud $estado, int $diasParaVencer): Solicitud {
        return Solicitud::create([
            'sistema' => 'discapacidad',
            'tipo' => TipoDeSolicitud::Acceso,
            'estado' => $estado,
            'titular_type' => $this->titular->getMorphClass(),
            'titular_id' => $this->titular->getKey(),
            'titular_ref' => hash('sha256', '11.111.111-1'),
            'recibida_en' => now(),
            'vence_en' => now()->addDays($diasParaVencer),
        ]);
    };
});

it('una solicitud resuelta ya no tiene semáforo de plazo', function () {
    $solicitud = ($this->solicitud)(EstadoDeSolicitud::Acogida, -30);

    expect(PlazoLegal::de($solicitud))->toBe(EstadoDePlazo::Resuelta);
});

it('una solicitud pendiente con el plazo cumplido está vencida', function () {
    $solicitud = ($this->solicitud)(EstadoDeSolicitud::EnTramite, -1);

    expect(PlazoLegal::de($solicitud))->toBe(EstadoDePlazo::Vencida);
});

it('el umbral de por vencer es el mismo que usa el scope del modelo', function () {
    $solicitud = ($this->solicitud)(EstadoDeSolicitud::Recibida, PlazoLegal::DIAS_POR_VENCER);

    expect(PlazoLegal::de($solicitud))->toBe(EstadoDePlazo::PorVencer)
        ->and(Solicitud::query()->porVencer()->whereKey($solicitud->getKey())->exists())->toBeTrue();
});

it('con holgura de sobra está en plazo', function () {
    $solicitud = ($this->solicitud)(EstadoDeSolicitud::Recibida, PlazoLegal::DIAS_POR_VENCER + 10);

    expect(PlazoLegal::de($solicitud))->toBe(EstadoDePlazo::EnPlazo);
});

it('cada estado de plazo se nombra en español una sola vez', function () {
    expect(EstadoDePlazo::Resuelta->etiqueta())->toBe('Resuelta')
        ->and(EstadoDePlazo::Vencida->etiqueta())->toBe('Vencida')
        ->and(EstadoDePlazo::PorVencer->etiqueta())->toBe('Por vencer')
        ->and(EstadoDePlazo::EnPlazo->etiqueta())->toBe('En plazo');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/PlazoLegalTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Ciclo\EstadoDePlazo" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Privacidad/Ciclo/EstadoDePlazo.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Ciclo;

/**
 * El semáforo de plazo de una solicitud ARCOP.
 *
 * Es un estado, no un color: el panel de Filament lo pinta con su paleta y el
 * panel Blade con la suya, pero la regla de cuándo una solicitud está vencida
 * es una sola y es legal.
 */
enum EstadoDePlazo: string
{
    case Resuelta = 'resuelta';
    case Vencida = 'vencida';
    case PorVencer = 'por_vencer';
    case EnPlazo = 'en_plazo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Resuelta => 'Resuelta',
            self::Vencida => 'Vencida',
            self::PorVencer => 'Por vencer',
            self::EnPlazo => 'En plazo',
        };
    }
}
```

`src/Privacidad/Ciclo/PlazoLegal.php`:

```php
<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * En qué punto del plazo legal está una solicitud.
 *
 * Una solicitud ya resuelta no tiene «vencida» ni «por vencer»: el plazo dejó
 * de importar el día que se cerró el caso.
 */
final class PlazoLegal
{
    /**
     * Cuántos días antes del vencimiento se avisa.
     *
     * Vive acá y no repetido en `Solicitud::scopePorVencer()`: dos umbrales
     * paralelos envejecen mal, y este es el número que decide si un caso
     * aparece o no en la bandeja de urgentes.
     */
    public const DIAS_POR_VENCER = 5;

    public static function de(Solicitud $solicitud): EstadoDePlazo
    {
        if ($solicitud->estado->estaResuelta()) {
            return EstadoDePlazo::Resuelta;
        }

        $dias = $solicitud->diasRestantes();

        if ($dias < 0) {
            return EstadoDePlazo::Vencida;
        }

        return $dias <= self::DIAS_POR_VENCER
            ? EstadoDePlazo::PorVencer
            : EstadoDePlazo::EnPlazo;
    }
}
```

En `src/Privacidad/Modelos/Solicitud.php`, cambiar la firma del scope para que tome el umbral del núcleo:

```php
public function scopePorVencer(Builder $query, int $dias = PlazoLegal::DIAS_POR_VENCER): void
```

…y agregar el `use Muni\Shared\Privacidad\Ciclo\PlazoLegal;` correspondiente.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/PlazoLegalTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src/Privacidad/Ciclo tests/Privacidad/Ciclo
composer test
git add src/Privacidad/Ciclo tests/Privacidad/Ciclo src/Privacidad/Modelos/Solicitud.php
git commit -m "feat(ciclo): el semáforo de plazo pasa a ser una regla del módulo, no del panel"
```

---

### Task 2: `SeparacionDeFunciones`

**Files:**
- Create: `src/Privacidad/Ciclo/SeparacionDeFunciones.php`
- Test: `tests/Privacidad/Ciclo/SeparacionDeFuncionesTest.php`

**Interfaces:**
- Consumes: `Solicitud` (atributo `user_registro_id`).
- Produces: `SeparacionDeFunciones::advertencia(Solicitud $solicitud, int|string|null $quienResuelve): ?string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Muni\Shared\Privacidad\Ciclo\SeparacionDeFunciones;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

function solicitudRecibidaPor(?int $userId): Solicitud
{
    $solicitud = new Solicitud([
        'sistema' => 'discapacidad',
        'tipo' => TipoDeSolicitud::Acceso,
        'estado' => EstadoDeSolicitud::Recibida,
    ]);
    $solicitud->setAttribute('user_registro_id', $userId);

    return $solicitud;
}

it('no advierte nada cuando resuelve otra persona', function () {
    expect(SeparacionDeFunciones::advertencia(solicitudRecibidaPor(7), 9))->toBeNull();
});

it('no advierte nada cuando no se sabe quién la recibió', function () {
    expect(SeparacionDeFunciones::advertencia(solicitudRecibidaPor(null), 9))->toBeNull();
});

it('advierte cuando quien resuelve es quien recibió', function () {
    expect(SeparacionDeFunciones::advertencia(solicitudRecibidaPor(7), 7))
        ->toContain('Esta solicitud la recibiste tú');
});

it('no confunde a un invitado sin sesión con quien recibió', function () {
    expect(SeparacionDeFunciones::advertencia(solicitudRecibidaPor(null), null))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/SeparacionDeFuncionesTest.php`
Expected: FAIL — `Class "Muni\Shared\Privacidad\Ciclo\SeparacionDeFunciones" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * Aviso —no prohibición— cuando quien va a resolver es quien recibió.
 *
 * El módulo permite que sean la misma persona: en un municipio chico el mismo
 * funcionario atiende el mesón y resuelve, y eso lo decide el municipio, no
 * este código. Lo que sí hace es que la coincidencia se vea justo en el momento
 * de resolver.
 *
 * Quién resuelve llega por parámetro y no de `auth()`: el núcleo no supone que
 * hay una sesión web —una resolución puede venir de un comando— y así la regla
 * se puede probar sin montar autenticación.
 */
final class SeparacionDeFunciones
{
    public static function advertencia(Solicitud $solicitud, int|string|null $quienResuelve): ?string
    {
        $registro = $solicitud->getAttribute('user_registro_id');

        if ($registro === null || $quienResuelve === null) {
            return null;
        }

        if ((string) $registro !== (string) $quienResuelve) {
            return null;
        }

        return 'Esta solicitud la recibiste tú. Resolverla tú mismo concentra la recepción y la resolución en '
            .'una sola persona: si hay alguien más que pueda resolverla, mejor que la resuelva esa persona.';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/SeparacionDeFuncionesTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src tests && composer test
git add src/Privacidad/Ciclo/SeparacionDeFunciones.php tests/Privacidad/Ciclo/SeparacionDeFuncionesTest.php
git commit -m "feat(ciclo): la separación de funciones se comprueba sin depender de la sesión web"
```

---

### Task 3: `ResultadosDisponibles`

Qué resultados se pueden elegir a mano al resolver, según el tipo. Es regla legal: acoger a mano una rectificación sella una corrección que no ocurrió, y acoger a mano una supresión sella un borrado que no ocurrió.

**Files:**
- Create: `src/Privacidad/Ciclo/ResultadosDisponibles.php`
- Test: `tests/Privacidad/Ciclo/ResultadosDisponiblesTest.php`

**Interfaces:**
- Consumes: `TipoDeSolicitud`, `EstadoDeSolicitud::etiqueta()`.
- Produces: `ResultadosDisponibles::para(TipoDeSolicitud $tipo): array<string, string>` y `ResultadosDisponibles::nota(TipoDeSolicitud $tipo): ?string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Muni\Shared\Privacidad\Ciclo\ResultadosDisponibles;
use Muni\Shared\Privacidad\TipoDeSolicitud;

it('un acceso se puede acoger, acoger en parte o rechazar', function () {
    expect(ResultadosDisponibles::para(TipoDeSolicitud::Acceso))->toBe([
        'acogida' => 'Acogida',
        'acogida_parcial' => 'Acogida parcial',
        'rechazada' => 'Rechazada',
    ]);
});

it('una rectificación solo se puede rechazar a mano', function () {
    expect(ResultadosDisponibles::para(TipoDeSolicitud::Rectificacion))->toBe([
        'rechazada' => 'Rechazada',
    ]);
});

it('una supresión solo se puede rechazar a mano', function () {
    expect(ResultadosDisponibles::para(TipoDeSolicitud::Supresion))->toBe([
        'rechazada' => 'Rechazada',
    ]);
});

it('explica por dónde se acoge una rectificación', function () {
    expect(ResultadosDisponibles::nota(TipoDeSolicitud::Rectificacion))
        ->toContain('acoger sin corregir dejaría el dato como está');
});

it('explica por dónde se acoge una supresión', function () {
    expect(ResultadosDisponibles::nota(TipoDeSolicitud::Supresion))
        ->toContain('sellaría un borrado que no ocurrió');
});

it('no molesta con notas donde no hacen falta', function () {
    expect(ResultadosDisponibles::nota(TipoDeSolicitud::Oposicion))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/ResultadosDisponiblesTest.php`
Expected: FAIL — clase no encontrada.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

/**
 * Con qué resultados se puede cerrar a mano una solicitud.
 *
 * A una rectificación y a una supresión se les quitan las DOS acogidas, no solo
 * la total: «acogida parcial» a mano sellaría una corrección o un cese sin
 * corregir ni suprimir nada, o sea nada más que el papel. Esas se acogen
 * ejecutando la acción correspondiente, que es la que escribe y propaga.
 */
final class ResultadosDisponibles
{
    /** @return array<string, string> */
    public static function para(TipoDeSolicitud $tipo): array
    {
        $estados = match ($tipo) {
            TipoDeSolicitud::Rectificacion, TipoDeSolicitud::Supresion => [EstadoDeSolicitud::Rechazada],
            default => [
                EstadoDeSolicitud::Acogida,
                EstadoDeSolicitud::AcogidaParcial,
                EstadoDeSolicitud::Rechazada,
            ],
        };

        $resultados = [];

        foreach ($estados as $estado) {
            $resultados[$estado->value] = $estado->etiqueta();
        }

        return $resultados;
    }

    public static function nota(TipoDeSolicitud $tipo): ?string
    {
        return match ($tipo) {
            TipoDeSolicitud::Rectificacion => 'Para acogerla, usa «Rectificar»: acoger sin corregir dejaría el dato como está.',
            TipoDeSolicitud::Supresion => 'Para acogerla, usa «Suprimir»: acoger sin suprimir sellaría un borrado que no ocurrió. '
                .'El rechazo sí se resuelve acá, con tu propio fundamento.',
            default => null,
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/ResultadosDisponiblesTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src tests && composer test
git add src/Privacidad/Ciclo/ResultadosDisponibles.php tests/Privacidad/Ciclo/ResultadosDisponiblesTest.php
git commit -m "feat(ciclo): qué resultados admite cada tipo de solicitud deja de ser cosa del panel"
```

---

### Task 4: `EtiquetaDeTitular`

**Files:**
- Create: `src/Privacidad/Ciclo/EtiquetaDeTitular.php`
- Test: `tests/Privacidad/Ciclo/EtiquetaDeTitularTest.php`

**Interfaces:**
- Consumes: `Solicitud` (relación `titular`, atributo `titular_id`), contrato `TitularDeDatos`.
- Produces: `EtiquetaDeTitular::estaAnonimizada(Solicitud $solicitud): bool`, `EtiquetaDeTitular::deLaSolicitud(Solicitud $solicitud): string`, `EtiquetaDeTitular::de(?TitularDeDatos $titular): ?string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Ciclo\EtiquetaDeTitular;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'atencion', 'nombre' => 'Atenciones',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    $this->crear = function (?PersonaDePrueba $titular): Solicitud {
        return Solicitud::create([
            'sistema' => 'discapacidad',
            'tipo' => TipoDeSolicitud::Acceso,
            'estado' => EstadoDeSolicitud::Recibida,
            'titular_type' => $titular?->getMorphClass(),
            'titular_id' => $titular?->getKey(),
            'titular_ref' => hash('sha256', '11.111.111-1'),
            'recibida_en' => now(),
            'vence_en' => now()->addDays(30),
        ]);
    };
});

it('nombra al titular por el contrato, con su documento tal cual', function () {
    $solicitud = ($this->crear)($this->titular);

    expect(EtiquetaDeTitular::deLaSolicitud($solicitud))->toBe('Rocío Paredes (11.111.111-1)');
});

it('un caso anonimizado se muestra como lo que es', function () {
    $solicitud = ($this->crear)(null);

    expect(EtiquetaDeTitular::estaAnonimizada($solicitud))->toBeTrue()
        ->and(EtiquetaDeTitular::deLaSolicitud($solicitud))->toBe('Caso anonimizado');
});

it('un titular huérfano no se muestra como una fila rota', function () {
    $solicitud = ($this->crear)($this->titular);
    $this->titular->forceDelete();
    $solicitud->unsetRelation('titular');

    expect(EtiquetaDeTitular::estaAnonimizada($solicitud))->toBeFalse()
        ->and(EtiquetaDeTitular::deLaSolicitud($solicitud))->toBe('Titular no disponible');
});

it('en el buscador el titular se nombra con guion', function () {
    expect(EtiquetaDeTitular::de($this->titular))->toBe('Rocío Paredes — 11.111.111-1')
        ->and(EtiquetaDeTitular::de(null))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/EtiquetaDeTitularTest.php`
Expected: FAIL — clase no encontrada.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Solicitud;

/**
 * Cómo se nombra a un titular en pantalla.
 *
 * Siempre por el contrato, nunca por columnas del adoptante, y con el documento
 * TAL COMO lo devuelve `titularDocumento()`: hay registros con pasaporte y hay
 * sistemas que no identifican por RUT, y darles formato de RUT los vuelve
 * irreconocibles en el buscador.
 */
final class EtiquetaDeTitular
{
    /**
     * El titular es un morph y puede estar huérfano: la anonimización por
     * retención anula `titular_id` a propósito.
     */
    public static function estaAnonimizada(Solicitud $solicitud): bool
    {
        return $solicitud->getAttribute('titular_id') === null;
    }

    public static function deLaSolicitud(Solicitud $solicitud): string
    {
        if (self::estaAnonimizada($solicitud)) {
            return 'Caso anonimizado';
        }

        $titular = $solicitud->titular;

        if ($titular === null) {
            // `titular_id` existe pero el registro ya no: huérfano sin haber
            // pasado por la anonimización del módulo.
            return 'Titular no disponible';
        }

        if ($titular instanceof TitularDeDatos) {
            return $titular->titularNombre().' ('.$titular->titularDocumento().')';
        }

        return class_basename($titular).' #'.($titular instanceof Model ? $titular->getKey() : '');
    }

    public static function de(?TitularDeDatos $titular): ?string
    {
        return $titular === null
            ? null
            : $titular->titularNombre().' — '.$titular->titularDocumento();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/EtiquetaDeTitularTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src tests && composer test
git add src/Privacidad/Ciclo/EtiquetaDeTitular.php tests/Privacidad/Ciclo/EtiquetaDeTitularTest.php
git commit -m "feat(ciclo): nombrar al titular —anonimizado, huérfano o vivo— es del módulo"
```

---

### Task 5: `AlcanceDelCese`

Qué deja de hacer un sistema cuando un bloqueo queda vigente, y qué pasó con el bloqueo según cómo se resolvió. Hoy el texto por defecto vive en `PanelArcopPlugin::textoDelCese()` y la lógica del bloqueo en `SolicitudResource::efectoSobreElBloqueo()`.

**Files:**
- Create: `src/Privacidad/Ciclo/AlcanceDelCese.php`
- Test: `tests/Privacidad/Ciclo/AlcanceDelCeseTest.php`

**Interfaces:**
- Consumes: `TipoDeSolicitud`, `EstadoDeSolicitud::esAcogida()`.
- Produces: `new AlcanceDelCese(?string $declarado = null)`, `->texto(): string`, `->fueDeclarado(): bool`, `->efectoSobreElBloqueo(TipoDeSolicitud $tipo, EstadoDeSolicitud $resultado): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Muni\Shared\Privacidad\Ciclo\AlcanceDelCese;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

it('sin declarar, dice que no se declaró en vez de tranquilizar', function () {
    $alcance = new AlcanceDelCese;

    expect($alcance->fueDeclarado())->toBeFalse()
        ->and($alcance->texto())->toContain('no declaró qué deja de hacer');
});

it('declarado, dice exactamente lo que el sistema deja de hacer', function () {
    $alcance = new AlcanceDelCese('Deja de aparecer en los listados de derivación y no recibe más avisos.');

    expect($alcance->fueDeclarado())->toBeTrue()
        ->and($alcance->texto())->toBe('Deja de aparecer en los listados de derivación y no recibe más avisos.');
});

it('acoger una oposición vuelve el bloqueo definitivo', function () {
    $aviso = (new AlcanceDelCese('El sistema deja de derivar sus requerimientos.'))
        ->efectoSobreElBloqueo(TipoDeSolicitud::Oposicion, EstadoDeSolicitud::Acogida);

    expect($aviso)->toContain('El bloqueo queda DEFINITIVO')
        ->and($aviso)->toContain('El sistema deja de derivar sus requerimientos.');
});

it('acoger parcialmente una oposición también hace cesar el tratamiento', function () {
    $aviso = (new AlcanceDelCese)
        ->efectoSobreElBloqueo(TipoDeSolicitud::Oposicion, EstadoDeSolicitud::AcogidaParcial);

    expect($aviso)->toContain('El bloqueo queda DEFINITIVO');
});

it('rechazar una oposición levanta el bloqueo', function () {
    $aviso = (new AlcanceDelCese)
        ->efectoSobreElBloqueo(TipoDeSolicitud::Oposicion, EstadoDeSolicitud::Rechazada);

    expect($aviso)->toBe('Si había un bloqueo por esta solicitud, el módulo lo levantó.');
});

it('acoger un acceso no vuelve definitivo ningún bloqueo', function () {
    $aviso = (new AlcanceDelCese)
        ->efectoSobreElBloqueo(TipoDeSolicitud::Acceso, EstadoDeSolicitud::Acogida);

    expect($aviso)->toBe('Si había un bloqueo por esta solicitud, el módulo lo levantó.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/AlcanceDelCeseTest.php`
Expected: FAIL — clase no encontrada.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;

/**
 * Qué deja de hacer ESTE sistema cuando un bloqueo queda vigente.
 *
 * La frase es la que el funcionario le repite al vecino, así que la escribe el
 * adoptante y no el paquete: el mapeo tratamiento→finalidad —qué pantalla, qué
 * CSV, qué correo y qué job dejan de tocar a esa persona— es propio de cada
 * sistema, y el módulo no lo conoce ni lo puede ejecutar.
 *
 * Que el default diga «no lo declaró» en vez de una frase tranquilizadora es
 * deliberado: recibir y resolver solicitudes es la SUPERFICIE del cumplimiento,
 * no el cumplimiento. Un sistema que herede el panel y no escriba su candado le
 * certificaría por escrito a un vecino un cese que no ocurre.
 */
final readonly class AlcanceDelCese
{
    public function __construct(private ?string $declarado = null) {}

    public function fueDeclarado(): bool
    {
        return $this->declarado !== null && trim($this->declarado) !== '';
    }

    public function texto(): string
    {
        return $this->fueDeclarado()
            ? (string) $this->declarado
            : 'Este sistema no declaró qué deja de hacer con los datos de esta persona cuando el bloqueo queda '
                .'vigente. Antes de decirle al titular que su tratamiento cesó, confirmarlo con informática: el bloqueo '
                .'queda anotado, pero que se respete depende de un candado que este sistema tiene que haber escrito.';
    }

    /**
     * Qué pasó con el bloqueo, que no es lo mismo según cómo se resolvió.
     *
     * Una oposición ACOGIDA no levanta el bloqueo: lo vuelve definitivo. Decir
     * lo contrario dejaría entender que el vecino quedó como antes, justo
     * cuando se le dio la razón.
     */
    public function efectoSobreElBloqueo(TipoDeSolicitud $tipo, EstadoDeSolicitud $resultado): string
    {
        $cesa = $resultado->esAcogida() && $tipo === TipoDeSolicitud::Oposicion;

        if (! $cesa) {
            return 'Si había un bloqueo por esta solicitud, el módulo lo levantó.';
        }

        return 'El bloqueo queda DEFINITIVO: el tratamiento cesa. '.$this->texto();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/AlcanceDelCeseTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src tests && composer test
git add src/Privacidad/Ciclo/AlcanceDelCese.php tests/Privacidad/Ciclo/AlcanceDelCeseTest.php
git commit -m "feat(ciclo): el alcance del cese y su efecto sobre el bloqueo bajan al módulo"
```

---

### Task 6: `ResumenDeSupresion`

Lo que hay que poder decirle al titular después de una supresión. Hoy es un `Notification` de Filament que redacta el texto a mano; el núcleo entrega los hechos y cada panel los redacta.

**Files:**
- Create: `src/Privacidad/Ciclo/ResumenDeSupresion.php`
- Test: `tests/Privacidad/Ciclo/ResumenDeSupresionTest.php`

**Interfaces:**
- Consumes: `ResultadoDeSupresion` (`$total`, `$barrido->archivosSuprimidos`, `$barrido->archivosNoEncontrados`, `$propagacion?->loAceptoElMaestro()`).
- Produces: `ResumenDeSupresion::de(ResultadoDeSupresion $resultado): self` con propiedades públicas `bool $total`, `int $archivosSuprimidos`, `int $archivosNoEncontrados`, `bool $salioDelEcosistema`, y método `titulo(): string`, `cuerpo(): string`, `esAdvertencia(): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Muni\Shared\Privacidad\Ciclo\ResumenDeSupresion;
use Muni\Shared\Privacidad\EvaluacionDeSupresion;
use Muni\Shared\Privacidad\ResultadoDePropagacion;
use Muni\Shared\Privacidad\ResultadoDesvinculacion;
use Muni\Shared\Privacidad\ResultadoDeSupresion;

function evaluacionQueProcede(): EvaluacionDeSupresion
{
    return new EvaluacionDeSupresion(codigosQueCesan: ['atencion'], codigosQueImpiden: []);
}

it('una supresión total con el maestro conforme se cuenta como cierre', function () {
    $resumen = ResumenDeSupresion::de(new ResultadoDeSupresion(
        total: true,
        evaluacion: evaluacionQueProcede(),
        barrido: new ResultadoDesvinculacion(archivosSuprimidos: 2, archivosNoEncontrados: 0),
        propagacion: ResultadoDePropagacion::aceptada(),
    ));

    expect($resumen->total)->toBeTrue()
        ->and($resumen->salioDelEcosistema)->toBeTrue()
        ->and($resumen->esAdvertencia())->toBeFalse()
        ->and($resumen->cuerpo())->toContain('2 documento(s)');
});

it('una total sin propagación aceptada es una advertencia, no un listo', function () {
    $resumen = ResumenDeSupresion::de(new ResultadoDeSupresion(
        total: true,
        evaluacion: evaluacionQueProcede(),
        barrido: new ResultadoDesvinculacion(archivosSuprimidos: 1, archivosNoEncontrados: 0),
        propagacion: null,
    ));

    expect($resumen->salioDelEcosistema)->toBeFalse()
        ->and($resumen->esAdvertencia())->toBeTrue()
        ->and($resumen->titulo())->toContain('sigue');
});

it('una ruta sin archivo se avisa: el documento puede estar en otro disco', function () {
    $resumen = ResumenDeSupresion::de(new ResultadoDeSupresion(
        total: true,
        evaluacion: evaluacionQueProcede(),
        barrido: new ResultadoDesvinculacion(archivosSuprimidos: 0, archivosNoEncontrados: 3),
        propagacion: ResultadoDePropagacion::aceptada(),
    ));

    expect($resumen->archivosNoEncontrados)->toBe(3)
        ->and($resumen->cuerpo())->toContain('otro disco');
});

it('una acogida parcial no se anuncia con el mismo listo que una total', function () {
    $resumen = ResumenDeSupresion::de(new ResultadoDeSupresion(
        total: false,
        evaluacion: evaluacionQueProcede(),
    ));

    expect($resumen->total)->toBeFalse()
        ->and($resumen->cuerpo())->not->toContain('quedó anonimizado')
        ->and($resumen->cuerpo())->toContain('parcial');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/ResumenDeSupresionTest.php`
Expected: FAIL — clase no encontrada.

Si además falla por las firmas de `EvaluacionDeSupresion`, `ResultadoDesvinculacion` o `ResultadoDePropagacion`, **leer esas tres clases y ajustar la construcción del test a sus constructores reales antes de seguir** — el test tiene que armar los objetos como los arma el módulo, no como se suponía acá.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\ResultadoDeSupresion;

/**
 * Qué pasó realmente en una supresión, que es lo que hay que poder decirle al
 * titular.
 *
 * Los desenlaces se cuentan distinto a propósito: una acogida parcial NO borró
 * nada, y anunciarla con el mismo «listo» que la supresión total sería la
 * confusión que esta clase existe para cerrar. Y dentro de la total hay una
 * segunda distinción: destruir el dato local no es lo mismo que sacar al vecino
 * del ecosistema. Hasta que el maestro de personas conteste que aceptó, no hay
 * con qué afirmar que la identidad dejó de servirse por RUT a los otros
 * sistemas.
 */
final readonly class ResumenDeSupresion
{
    public function __construct(
        public bool $total,
        public int $archivosSuprimidos,
        public int $archivosNoEncontrados,
        public bool $salioDelEcosistema,
    ) {}

    public static function de(ResultadoDeSupresion $resultado): self
    {
        return new self(
            total: $resultado->total,
            archivosSuprimidos: $resultado->barrido->archivosSuprimidos ?? 0,
            archivosNoEncontrados: $resultado->barrido->archivosNoEncontrados ?? 0,
            salioDelEcosistema: $resultado->propagacion?->loAceptoElMaestro() ?? false,
        );
    }

    public function esAdvertencia(): bool
    {
        return $this->total && ! $this->salioDelEcosistema;
    }

    public function titulo(): string
    {
        if (! $this->total) {
            return 'Supresión acogida en parte';
        }

        return $this->salioDelEcosistema
            ? 'Datos suprimidos'
            : 'Suprimido acá, pero la identidad sigue en el ecosistema';
    }

    public function cuerpo(): string
    {
        if (! $this->total) {
            return 'La supresión procede solo en parte: el módulo cesó las finalidades que podían cesar y dejó '
                .'las que una norma obliga a conservar. No se borró el registro.';
        }

        $cuerpo = 'El registro quedó anonimizado, se purgaron sus datos sensibles y el módulo borró '
            .$this->archivosSuprimidos.' documento(s) del disco. La solicitud queda acogida.';

        if ($this->archivosNoEncontrados > 0) {
            $cuerpo .= ' Ojo: '.$this->archivosNoEncontrados.' ruta(s) no tenían archivo donde el módulo buscó. '
                .'Avisar a informática para descartar que el documento haya quedado en otro disco.';
        }

        if (! $this->salioDelEcosistema) {
            $cuerpo .= ' El maestro de personas todavía no confirmó la baja: hasta que lo haga, la identidad se '
                .'puede seguir sirviendo por RUT a los otros sistemas.';
        }

        return $cuerpo;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/ResumenDeSupresionTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src tests && composer test
git add src/Privacidad/Ciclo/ResumenDeSupresion.php tests/Privacidad/Ciclo/ResumenDeSupresionTest.php
git commit -m "feat(ciclo): el resumen de una supresión entrega hechos, no un texto de panel"
```

---

### Task 7: `PreviaDeSupresion`

**Files:**
- Create: `src/Privacidad/Ciclo/PreviaDeSupresion.php`
- Test: `tests/Privacidad/Ciclo/PreviaDeSupresionTest.php`

**Interfaces:**
- Consumes: `Supresiones::evaluar(TitularDeDatos $titular): EvaluacionDeSupresion` (`->explicacion(): string`), `SeparacionDeFunciones::advertencia()`.
- Produces: `PreviaDeSupresion::de(Solicitud $solicitud): ?string` y `PreviaDeSupresion::antesDeSuprimir(Solicitud $solicitud, int|string|null $quienResuelve): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Ciclo\PreviaDeSupresion;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'atencion', 'nombre' => 'Atenciones',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    $this->solicitud = Solicitud::create([
        'sistema' => 'discapacidad',
        'tipo' => TipoDeSolicitud::Supresion,
        'estado' => EstadoDeSolicitud::EnTramite,
        'titular_type' => $this->titular->getMorphClass(),
        'titular_id' => $this->titular->getKey(),
        'titular_ref' => hash('sha256', '11.111.111-1'),
        'recibida_en' => now(),
        'vence_en' => now()->addDays(30),
    ]);
    $this->solicitud->setAttribute('user_registro_id', 7);
    $this->solicitud->save();
});

it('muestra hasta dónde llega el derecho antes de tocar nada', function () {
    $previa = PreviaDeSupresion::de($this->solicitud);

    expect($previa)->toBeString()->not->toBeEmpty();
});

it('no inventa una previa para un caso anonimizado', function () {
    $this->solicitud->forceFill(['titular_id' => null])->save();
    $this->solicitud->unsetRelation('titular');

    expect(PreviaDeSupresion::de($this->solicitud->fresh()))->toBeNull();
});

it('la previa NO escribe nada: la solicitud queda en trámite', function () {
    PreviaDeSupresion::de($this->solicitud);

    expect($this->solicitud->fresh()->estado)->toBe(EstadoDeSolicitud::EnTramite)
        ->and(PersonaDePrueba::find($this->titular->getKey()))->not->toBeNull();
});

it('junta el aviso de separación de funciones con la previa', function () {
    $texto = PreviaDeSupresion::antesDeSuprimir($this->solicitud, 7);

    expect($texto)->toContain('Esta solicitud la recibiste tú');
});

it('sin coincidencia de funcionario, entrega solo la previa', function () {
    $texto = PreviaDeSupresion::antesDeSuprimir($this->solicitud, 9);

    expect($texto)->not->toContain('Esta solicitud la recibiste tú');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/PreviaDeSupresionTest.php`
Expected: FAIL — clase no encontrada.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Muni\Shared\Privacidad\Ciclo;

use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\Supresiones;

/**
 * Lo que el funcionario ve ANTES de suprimir: hasta dónde llega el derecho de
 * este titular según el RAT.
 *
 * `Supresiones::evaluar()` no escribe nada —existe justamente para poder
 * mostrar esto antes de resolver— y su explicación cita la norma y el plazo,
 * que es lo que el funcionario tiene que copiar en el fundamento.
 */
final class PreviaDeSupresion
{
    public static function de(Solicitud $solicitud): ?string
    {
        $titular = $solicitud->titular;

        if (! $titular instanceof TitularDeDatos) {
            return null;
        }

        return app(Supresiones::class)->evaluar($titular)->explicacion();
    }

    /** La previa, junto con el aviso de separación de funciones si corresponde. */
    public static function antesDeSuprimir(Solicitud $solicitud, int|string|null $quienResuelve): string
    {
        return trim(implode("\n\n", array_filter([
            SeparacionDeFunciones::advertencia($solicitud, $quienResuelve),
            self::de($solicitud),
        ])));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/PreviaDeSupresionTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src tests && composer test
git add src/Privacidad/Ciclo/PreviaDeSupresion.php tests/Privacidad/Ciclo/PreviaDeSupresionTest.php
git commit -m "feat(ciclo): la previa de supresión se puede pedir sin un panel de por medio"
```

---

### Task 8: El contrato `BuscaTitulares` baja al módulo

Hoy vive en `Muni\Ui\Filament\Privacidad\Contratos\BuscaTitulares`, o sea que un panel sin Filament no lo puede implementar. Si los dos paneles buscan titulares distinto, el vecino recibe respuestas distintas según el mesón.

**Files:**
- Create: `src/Privacidad/Contratos/BuscaTitulares.php`
- Test: `tests/Privacidad/Ciclo/BuscaTitularesTest.php`

**Interfaces:**
- Produces: `interface Muni\Shared\Privacidad\Contratos\BuscaTitulares { public function buscar(string $texto): array; }` — devuelve `array<int|string, string>` (clave del titular ⇒ etiqueta).

- [ ] **Step 1: Write the failing test**

```php
<?php

use Muni\Shared\Privacidad\Ciclo\EtiquetaDeTitular;
use Muni\Shared\Privacidad\Contratos\BuscaTitulares;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

it('un buscador del adoptante cumple el contrato del módulo', function () {
    PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    $buscador = new class implements BuscaTitulares
    {
        public function buscar(string $texto): array
        {
            return PersonaDePrueba::query()
                ->where('nombre', 'like', '%'.$texto.'%')
                ->get()
                ->mapWithKeys(fn (PersonaDePrueba $p): array => [
                    $p->getKey() => (string) EtiquetaDeTitular::de($p),
                ])
                ->all();
        }
    };

    expect($buscador->buscar('Rocío'))->toContain('Rocío Paredes — 11.111.111-1')
        ->and($buscador->buscar('Nadie'))->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/BuscaTitularesTest.php`
Expected: FAIL — `Interface "Muni\Shared\Privacidad\Contratos\BuscaTitulares" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Muni\Shared\Privacidad\Contratos;

/**
 * Cómo encuentra el adoptante a los titulares que atiende.
 *
 * Vive en el módulo y no en un paquete de panel porque los dos paneles del
 * ecosistema —el de Filament y el de Blade— tienen que buscar igual: si buscan
 * distinto, el mismo vecino recibe respuestas distintas según qué mesón lo
 * atendió.
 *
 * Quien lo implemente tiene dos obligaciones que el módulo no puede imponer por
 * código: exigir un mínimo de caracteres y acotar los resultados. Este buscador
 * es la superficie por donde se puede ENUMERAR el padrón de un municipio.
 */
interface BuscaTitulares
{
    /**
     * @return array<int|string, string> clave del titular ⇒ etiqueta que se muestra
     */
    public function buscar(string $texto): array;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Privacidad/Ciclo/BuscaTitularesTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src tests && composer test
git add src/Privacidad/Contratos/BuscaTitulares.php tests/Privacidad/Ciclo/BuscaTitularesTest.php
git commit -m "feat(privacidad): el contrato del buscador de titulares baja al módulo"
```

---

### Task 9: Publicar `laravel-muni-shared` v1.14.0

**Files:**
- Modify: `README.md` (sección nueva «El núcleo del ciclo ARCOP» con la tabla de clases)
- Modify: `CHANGELOG.md` si existe; si no, no crearlo

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: tag `v1.14.0` en el remoto, que es lo que consumen las tareas siguientes.

- [ ] **Step 1: Correr la suite completa en SQLite**

Run: `composer test`
Expected: toda la suite en verde, incluidos los tests que ya existían.

- [ ] **Step 2: Correr la suite contra MariaDB**

```bash
docker run --rm -d --name muni-shared-mariadb-test \
  -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=prueba \
  -p 33061:3306 mariadb:11
sleep 15
MUNI_MARIADB_HOST=127.0.0.1 MUNI_MARIADB_PORT=33061 vendor/bin/pest
docker rm -f muni-shared-mariadb-test
```

Expected: verde. Es obligatorio antes de publicar: la producción del ecosistema es MariaDB y la suite en SQLite ya escondió un defecto crítico.

- [ ] **Step 3: Documentar el núcleo en el README**

Agregar una sección con la tabla de clases de `Muni\Shared\Privacidad\Ciclo` (una fila por clase, qué decide y quién la usa), y la nota de que `BuscaTitulares` se movió desde `muni-ui` y que allá queda un alias deprecado.

- [ ] **Step 4: Commit y tag**

```bash
git add README.md
git commit -m "docs: el núcleo del ciclo ARCOP, para los dos paneles"
git tag -a v1.14.0 -m "Núcleo del ciclo ARCOP compartido entre paneles"
git push origin main --follow-tags
```

- [ ] **Step 5: Verificar que el tag llegó**

Run: `git ls-remote --tags origin | grep v1.14.0`
Expected: una línea con el tag. Sin esto, el paquete nuevo no lo puede requerir.

---

### Task 10: `laravel-muni-ui` delega en el núcleo

**Files:**
- Modify: `composer.json` (subir el piso de `muni-graneros/laravel-muni-shared` a `^1.14`)
- Modify: `src/Filament/Privacidad/SolicitudResource.php` (los métodos listados abajo pasan a delegar)
- Modify: `src/Filament/Privacidad/PanelArcopPlugin.php` (`textoDelCese()` delega en `AlcanceDelCese`)
- Modify: `src/Filament/Privacidad/Contratos/BuscaTitulares.php` (queda como alias deprecado del contrato del módulo)

**Interfaces:**
- Consumes: todo `Muni\Shared\Privacidad\Ciclo\*` y `Muni\Shared\Privacidad\Contratos\BuscaTitulares`.
- Produces: `muni-ui` v0.15.0, con el mismo comportamiento visible.

- [ ] **Step 1: Verificar en verde ANTES de tocar nada**

```bash
cd /home/cesar/Dev/laravel-muni-ui
composer update muni-graneros/laravel-muni-shared
vendor/bin/pest
```

Expected: verde. Este es el punto de comparación; sin él no se puede afirmar que la delegación no cambió nada.

- [ ] **Step 2: Delegar, método por método**

En `SolicitudResource`:

```php
public static function etiquetaPlazo(Solicitud $solicitud): string
{
    return PlazoLegal::de($solicitud)->etiqueta();
}

public static function colorPlazo(Solicitud $solicitud): string
{
    return match (PlazoLegal::de($solicitud)) {
        EstadoDePlazo::Resuelta => 'gray',
        EstadoDePlazo::Vencida => 'danger',
        EstadoDePlazo::PorVencer => 'warning',
        EstadoDePlazo::EnPlazo => 'success',
    };
}

public static function etiquetaEstado(EstadoDeSolicitud $estado): string
{
    return $estado->etiqueta();
}

public static function advertenciaSeparacionDeFunciones(Solicitud $solicitud): ?string
{
    return SeparacionDeFunciones::advertencia($solicitud, auth()->id());
}

public static function resultadosDisponibles(Solicitud $solicitud): array
{
    return ResultadosDisponibles::para($solicitud->tipo);
}

private static function notaDeResultados(Solicitud $solicitud): ?string
{
    return ResultadosDisponibles::nota($solicitud->tipo);
}

public static function estaAnonimizada(Solicitud $solicitud): bool
{
    return EtiquetaDeTitular::estaAnonimizada($solicitud);
}

public static function etiquetaTitular(Solicitud $solicitud): string
{
    return EtiquetaDeTitular::deLaSolicitud($solicitud);
}

public static function etiquetaDeTitular(?TitularDeDatos $titular): ?string
{
    return EtiquetaDeTitular::de($titular);
}

public static function previaDeSupresion(Solicitud $solicitud): ?string
{
    return PreviaDeSupresion::de($solicitud);
}

private static function antesDeSuprimir(Solicitud $solicitud): string
{
    return PreviaDeSupresion::antesDeSuprimir($solicitud, auth()->id());
}

public static function queCesaDeVerdad(): string
{
    return self::plugin()->textoDelCese();
}

private static function efectoSobreElBloqueo(Solicitud $solicitud, EstadoDeSolicitud $resultado): string
{
    return self::plugin()->alcance()->efectoSobreElBloqueo($solicitud->tipo, $resultado);
}
```

En `PanelArcopPlugin`, el texto por defecto sale de la clase del módulo:

```php
public function alcance(): AlcanceDelCese
{
    return new AlcanceDelCese($this->alcanceDelCese);
}

public function textoDelCese(): string
{
    return $this->alcance()->texto();
}
```

Y `avisarSupresion()` pasa a leer los hechos de `ResumenDeSupresion::de($resultado)` en vez de recalcularlos: el título sale de `titulo()`, el cuerpo de `cuerpo()`, y `esAdvertencia()` decide entre `->warning()` y `->success()`.

- [ ] **Step 3: El contrato viejo queda como alias deprecado**

```php
<?php

namespace Muni\Ui\Filament\Privacidad\Contratos;

use Muni\Shared\Privacidad\Contratos\BuscaTitulares as ContratoDelModulo;

/**
 * @deprecated Se movió a Muni\Shared\Privacidad\Contratos\BuscaTitulares, para
 *             que un panel sin Filament también lo pueda implementar. Esta
 *             interfaz queda para no romper a los adoptantes que ya la
 *             implementan y se saca en la próxima mayor.
 */
interface BuscaTitulares extends ContratoDelModulo {}
```

`PanelArcopPlugin::titulares()` acepta ambos: su firma pasa a tipar el contrato del módulo, que el alias extiende.

- [ ] **Step 4: Correr la suite y comparar con el paso 1**

Run: `vendor/bin/pest`
Expected: la misma cantidad de tests en verde que en el paso 1. Ni uno menos.

- [ ] **Step 5: Verificar contra el sistema real**

```bash
cd /home/cesar/Dev/discapacidad-graneros
composer config repositories.muni-ui '{"type":"path","url":"../laravel-muni-ui","options":{"symlink":false}}'
composer update muni-graneros/laravel-muni-ui muni-graneros/laravel-muni-shared
php artisan test
```

Expected: la suite de disc en verde **sin haber tocado disc**. Ojo con la trampa conocida: un repo `path` con `symlink: false` es una COPIA — si se edita el paquete hay que reinstalar, y `composer update` puede servir la copia cacheada. Terminada la comprobación, devolver el `composer.json` de disc a como estaba (`git checkout composer.json composer.lock`).

- [ ] **Step 6: Commit, tag y push**

```bash
cd /home/cesar/Dev/laravel-muni-ui
vendor/bin/pint src
git add composer.json src/Filament/Privacidad
git commit -m "refactor(arcop): el panel pasa a leer las reglas del módulo en vez de tenerlas propias"
git tag -a v0.15.0 -m "El panel ARCOP delega las reglas legales en el núcleo del módulo"
git push origin main --follow-tags
```

- [ ] **Step 7: Cerrar el hueco del tag v0.13.0**

Run: `git ls-remote --tags origin | grep -E 'v0\.(13|14|15)\.0'`
Expected: los tres tags en el remoto. `v0.13.0` existe local y **no está publicado**: si falta, empujarlo (`git push origin v0.13.0`), porque un adoptante que pida `^0.13` hoy no lo encuentra.

---

## Self-Review

**Cobertura del spec.** El Ciclo 0 del spec pide siete clases de núcleo más la mudanza del contrato: `PlazoLegal` (T1), `SeparacionDeFunciones` (T2), `ResultadosDisponibles` (T3), `EtiquetaDeTitular` (T4), `AlcanceDelCese` (T5), `ResumenDeSupresion` (T6), `PreviaDeSupresion` (T7), `BuscaTitulares` (T8). Publicación (T9) y delegación de muni-ui (T10). El criterio «disc verde sin tocar disc» se verifica en T10 paso 5.

**Fuera de alcance de este plan.** Los ciclos 1 (paquete `laravel-arcop-panel`) y 2 (piloto en `atencionvecino`) tienen sus propios planes: este termina con el núcleo publicado y el panel de Filament delegando.
