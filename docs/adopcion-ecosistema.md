# Adopción en el ecosistema: qué borra cada sistema y qué configura

Las guías de migración de las tres tandas de extracción (§1.4, §1.5 y §1.6 del
plan de adopción). Estaban en el README; se movieron acá porque son para quien
está sacando una copia local de un sistema municipal concreto, no para quien
consume el paquete. Dicen, pieza por pieza, qué archivo borra cada sistema y qué
queda configurado en su lugar.

Vuelta al [README](../README.md).

## Adopción de RolePolicy, ActivityPolicy y ListActivitiesBase (§1.4): qué borra cada sistema y qué configura

Comparadas las 15 copias en disco del ecosistema (7 sistemas municipales +
`atencionvecino` + `web-graneros-centinela` + `scaffold-laravel-filament-pwa` +
4 repos de KraftDo + `plataforma-graneros/personas-graneros`), **no todas son
candidatas**: KraftDo es una entidad distinta y no comparte código con la
Municipalidad bajo ninguna circunstancia, y `personas-graneros` todavía no
requiere este paquete. Lo que sigue cubre únicamente los sistemas municipales
que sí lo consumen.

### `RolePolicy` — idéntica byte a byte en los 7 municipales con panel

| Se borra | Se cambia |
|---|---|
| `app/Policies/RolePolicy.php` | la `policy()` que la registra (o el auto-discovery de Filament Shield) apunta a `Muni\Shared\Auditoria\RolePolicy` |

Nada más: delega todo en los permisos que Filament Shield ya genera
(`view_any_role`, `create_role`, …), así que no hay ningún nombre de rol
(`super_admin` vs `administrador`) que perder — eso lo sigue resolviendo el
`Gate::before` de cada sistema, fuera de esta política.

### `ActivityPolicy` — idéntica en 6 de 7, `feria-graneros` con una generación más vieja de Shield

| Se borra | Se cambia |
|---|---|
| `app/Policies/ActivityPolicy.php` | igual que arriba, apuntando a `Muni\Shared\Auditoria\ActivityPolicy` |

`feria-graneros` tipaba `App\Models\User` en vez de
`Illuminate\Foundation\Auth\User`, no traía `declare(strict_types=1)` y
documentaba cada método con un docblock en vez de nada — es un
`ActivityPolicy` generado por una versión más vieja de Filament Shield. Se
comprobó método por método contra el permiso que cada uno consulta
(`force_delete_activity`, `restore_activity`, …): son los mismos doce, en
otro orden. Es deuda de estilo, no una regla de negocio distinta, así que se
borra igual que las otras seis.

### `ListActivities` — idéntica en 5 de 7; `discapacidad-graneros` diverge en namespace, y eso es real

| Se borra | Se cambia |
|---|---|
| el `ListActivities.php` del sistema | pasa a extender `Muni\Shared\Auditoria\Pages\ListActivitiesBase` en vez de `Filament\Resources\Pages\ListRecords` |

```php
namespace App\Filament\Resources\ActivityResource\Pages;

use App\Filament\Resources\ActivityResource;
use Muni\Shared\Auditoria\Pages\ListActivitiesBase;

class ListActivities extends ListActivitiesBase
{
    protected static string $resource = ActivityResource::class;
}
```

`$resource` **no** se fija en el paquete: cada sistema tiene su propio
`ActivityResource`, con sus propias columnas de tabla, y eso no es portable —
es lo único que cada sistema sigue declarando.

`discapacidad-graneros` no es una copia de deuda sino una necesidad real: su
panel Filament vive en un namespace separado
(`App\Filament\Discapacidad\Resources\ActivityResource\Pages`, por su panel
multi-panel), y su `ListActivities` apunta a `ActivityResource` de ESE mismo
namespace. El patrón de arriba funciona igual ahí, cambiando solo el
namespace y el `use` de `ActivityResource`; no hace falta nada especial en el
paquete porque `ListActivitiesBase` es abstracta y no fija ningún namespace.
`laravel-kraftdo-shared` (KraftDo, otra entidad) resolvió el mismo problema
con el mismo patrón — `Kraftdo\Shared\Filament\Resources\ActivityResource\Pages`
extendiendo su propia base —, lo que confirma que la clase abstracta sin
`$resource` es la forma correcta y no una ocurrencia de este paquete.

### Qué NO se cubrió, y por qué

- **`plataforma-graneros/personas-graneros`** tiene su propio `RolePolicy.php`
  y `ActivityPolicy.php`, pero no son candidatos de esta migración: nunca
  llegó a requerir `muni-graneros/laravel-muni-shared`, y su `RolePolicy` ni
  siquiera es la misma generación de código que las 7 copias unificadas —viene
  de un `shield:generate` inicial que **nunca se completó con
  `--option=permissions`** (ver `gotcha_shield_permisos_no_aplicados` y
  `patron_reversion_userpolicy` en la memoria del ecosistema): `forceDelete`,
  `forceDeleteAny`, `restore`, `restoreAny`, `replicate` y `reorder` comprueban
  el permiso literal `'{{ ForceDelete }}'` (y equivalentes), un placeholder de
  Shield que ningún rol tiene, así que esos seis métodos siempre niegan. No es
  una necesidad de ese sistema: es el mismo defecto de generación ya
  documentado en el ecosistema, y corregirlo es tarea de `personas-graneros`
  cuando adopte este paquete, no de esta extracción — esta sesión no editó
  nada fuera de `laravel-muni-shared`.
- **Los 4 repos de KraftDo** (`kraftdo-nfc-v2`, `kraftdo-sitio`, `kraftdo-crm`,
  `kraftdo-hub`) tienen las tres piezas byte a byte idénticas a la versión
  unificada de la Municipalidad. No se tocan: KraftDo y la Municipalidad son
  entidades separadas y este paquete es `muni-graneros/laravel-muni-shared`,
  no compartido entre ambas.

## Adopción de lo pequeño (§1.5): qué borra cada sistema y qué configura

Seis piezas que vivían copiadas entre 5 y 7 veces. Se adoptan de a una y en
cualquier orden; ninguna depende de las otras. **Ninguna necesita Filament ni
Pest**: los dos traits de aserto son de PHPUnit puro, así que `atencionvecino`
—Blade sin Filament, PHPUnit 11 sin Pest— los usa igual.

### 1. `Casts\EncryptedSeguro` — 7 sistemas

| Se borra | Se cambia |
|---|---|
| `app/Casts/EncryptedSeguro.php` | el `use App\Casts\EncryptedSeguro;` de cada modelo pasa a `use Muni\Shared\Casts\EncryptedSeguro;` |

Nada más: el comportamiento es idéntico byte a byte, a propósito. Cambiarlo
dejaría ilegibles columnas que ya están escritas en siete bases de producción.

Para evidencia ARCOP no se usa este cast sino `Privacidad\CifradoCast`, que ante
un ciphertext manipulado truena en vez de devolver el valor crudo.

### 2. `Errores\ReporteDeErrores` — 7 sistemas

| Se borra | Se cambia |
|---|---|
| `app/Support/ReporteDeErrores.php` | el `use` en `bootstrap/app.php` |

La llamada no cambia (`ReporteDeErrores::vaADestinoPropio()`), así que el candado
`Muni\Candados\Candados\ErroresNoSalenDelPais` sigue verde **sin tocarlo**, desde
`laravel-muni-candados` 0.5.2: el candado resuelve la clase por lo que existe
—primero `App\Support\ReporteDeErrores`, para el sistema que todavía tiene la
suya, y si no, la de este paquete— y solo falla si no encuentra ninguna de las
dos, nombrándolas.

Con una versión de candados anterior a la 0.5.2 sí hay que decírselo a mano
(`Candados::erroresNoSalenDelPais(clase: \Muni\Shared\Errores\ReporteDeErrores::class)`),
porque el valor por omisión era la clase local y fallaba señalando una clase
recién borrada. Pasó de verdad al adoptar en `rrhh-graneros` y `feria-graneros`.
Pasar `clase:` sigue ganando sobre la resolución automática, así que fijarla al
valor viejo vuelve a romper: lo correcto hoy es no pasarla.

La lista de destinos ajenos **no** es configurable: una lista que se puede
acortar desde un `.env` no es un candado.

### 3. `asset_versionado()` — 7 sistemas

| Se borra | Se cambia |
|---|---|
| `app/Helpers/assets.php` y su entrada en `autoload.files` de `composer.json` | nada: las plantillas Blade siguen llamando `asset_versionado(...)` |

Después de borrarlo, `composer dump-autoload`. Mientras dure la transición los
dos archivos pueden convivir: la función del paquete lleva su guarda
`function_exists`.

**`web-graneros` es el caso especial:** su `assets.php` tiene además
`paquete_de_estilos()`, que es propia del empaquetado de CSS de ese sitio. Ese
archivo se recorta a esa función; `asset_versionado()` se borra igual.

### 4. `env:clean-data` — 5 sistemas (+ los dos scaffolds y web-graneros-centinela)

| Se borra | Se configura |
|---|---|
| `app/Console/Commands/CleanData.php` | nada, salvo que el sistema tenga tablas operativas propias |

El comando se registra solo y conserva su nombre, así que Makefiles, runbooks y
`docs/DOCUMENTACION_TECNICA.md` siguen siendo válidos. Si hay tablas operativas
propias:

```bash
php artisan vendor:publish --tag=datos-operativos-config
```

y se agregan a `config/datos-operativos.php`. Los valores por omisión son los
cuatro que tenía la constante (`onboarding_progress`, `jobs`, `job_batches`,
`failed_jobs`) más `activity_log` bajo `--auditoria`.

Detalle que importa fuera de MariaDB: el comando ahora usa
`Schema::withoutForeignKeyConstraints()` en vez de `SET FOREIGN_KEY_CHECKS=0`.

### 5. `config/permission.php` — el archivo NO se borra

Medido valor por valor: los 7 sistemas coinciden en todo, y lo único que se
aparta del valor por omisión de `spatie/laravel-permission` es
`register_octane_reset_listener => true`. Traer el archivo entero ataría este
paquete al esquema de configuración de spatie —las copias de cinco sistemas ya
se quedaron sin `models.team` y `models.default_model`, agregadas después— para
custodiar un booleano.

| Se borra | Se agrega |
|---|---|
| nada del `config/` | una prueba de tres líneas |

```php
use Muni\Shared\Testing\AssertPermisosNoSeQuedanPegados;

uses(AssertPermisosNoSeQuedanPegados::class);

it('los permisos no se quedan pegados entre peticiones de Octane', function () {
    static::assertPermisosNoSeQuedanPegados();
});
```

Los dos sistemas con la copia de 206 renglones del vendor (`discapacidad`,
`feria`) pueden recortarla a la de 52 que tienen los otros cinco: los valores son
los mismos.

### 6. `config/mfa.php` — el archivo NO se mueve

Es la única de las seis piezas que **no** era el mismo archivo: los siete son
cinco variantes distintas (`atencionvecino` describe un TOTP con `emisor` y sin
`show_code`; `control-acceso` agrega el tope de códigos enviados). Y esa
configuración ya tiene dueño: `laravel-muni-acceso` la expone como `acceso.mfa.*`
leyendo las mismas variables de entorno (`MFA_ENABLED`, `MFA_SHOW_CODE`). Un
`mfa.*` en este paquete sería un segundo interruptor para la misma cerradura.

Lo que las siete variantes sí comparten es una advertencia escrita en un
comentario que hoy no hace cumplir nadie. Eso es lo que viaja:

```php
use Muni\Shared\Testing\AssertSegundoFactorNoSeRegala;

uses(AssertSegundoFactorNoSeRegala::class);

it('en producción el segundo factor ni se apaga ni se regala', function () {
    // La suite corre con APP_ENV=testing: lo que se ejercita es qué pasaría
    // con esta configuración en producción.
    static::assertSegundoFactorNoSeRegala(enProduccion: true);
});
```

Lee `mfa.*` o `acceso.mfa.*`, la que exista, y no escribe ninguna configuración.

## Adopción de OnboardingTourPolicy y las páginas de Onboarding (§1.6): qué borra cada sistema y qué configura

Comparadas las 9 copias en disco (8 sistemas municipales con panel Filament +
`atencionvecino`, que no tiene panel Filament ni el módulo). El módulo entero
—política y las tres páginas del Resource— es la duplicación más clara que
quedaba en el ecosistema: una funcionalidad completa copiada, no un
fragmento.

### `OnboardingTourPolicy` — idéntica byte a byte en los 8

| Se borra | Se cambia |
|---|---|
| `app/Policies/OnboardingTourPolicy.php` | la `policy()` que la registra (o el auto-discovery de Filament Shield) apunta a `Muni\Shared\Onboarding\OnboardingTourPolicy` |

Delega todo en los permisos que Filament Shield ya genera
(`view_any_onboarding_tour`, `create_onboarding_tour`, …), igual que
`RolePolicy`/`ActivityPolicy`. La diferencia real con esas dos: el modelo que
autoriza (`App\Models\OnboardingTour`) es **local** de cada sistema, no un
modelo de un vendor que este paquete pueda requerir, así que la política del
paquete tipa `Illuminate\Database\Eloquent\Model` en vez del modelo
concreto. Laravel resuelve la política por la clase del modelo
(`Gate::getPolicyFor()`) y la invoca con la instancia real; el tipo más
genérico no cambia a quién autoriza.

### `ListOnboardingTours` — idéntica en 7 de 8; `licencias-graneros` diverge solo en estilo

| Se borra | Se cambia |
|---|---|
| el `ListOnboardingTours.php` del sistema | pasa a extender `Muni\Shared\Onboarding\Pages\ListOnboardingToursBase` en vez de `Filament\Resources\Pages\ListRecords` |

`licencias-graneros` importaba `Filament\Actions` y llamaba
`Actions\CreateAction::make()` en vez de `use Filament\Actions\CreateAction;`
+ `CreateAction::make()` — mismo `CreateAction`, otro estilo de `use`. Se
borra igual que las otras siete.

A diferencia de `Auditoria\ListActivitiesBase` —que queda vacía porque el
listado de auditoría no traía ninguna acción de cabecera—, acá SÍ viaja
`getHeaderActions()`: los 8 sistemas traían idéntico
`[CreateAction::make()]`, así que es funcionalidad duplicada, no solo el
sitio de herencia.

```php
namespace App\Filament\Resources\OnboardingTourResource\Pages;

use App\Filament\Resources\OnboardingTourResource;
use Muni\Shared\Onboarding\Pages\ListOnboardingToursBase;

class ListOnboardingTours extends ListOnboardingToursBase
{
    protected static string $resource = OnboardingTourResource::class;
}
```

### `EditOnboardingTour` — idéntica en 6 de 8; `licencias-graneros` y `feria-graneros` divergen solo en estilo

| Se borra | Se cambia |
|---|---|
| el `EditOnboardingTour.php` del sistema | pasa a extender `Muni\Shared\Onboarding\Pages\EditOnboardingTourBase` en vez de `Filament\Resources\Pages\EditRecord` |

Mismo patrón de estilo que `ListOnboardingTours` (`Actions\DeleteAction::make()`
contra `DeleteAction::make()`), la misma generación más vieja de Filament
Shield que ya se documentó en `ActivityPolicy` (§1.4). También trae
`getHeaderActions()` con `[DeleteAction::make()]`, idéntico en los 8.

```php
namespace App\Filament\Resources\OnboardingTourResource\Pages;

use App\Filament\Resources\OnboardingTourResource;
use Muni\Shared\Onboarding\Pages\EditOnboardingTourBase;

class EditOnboardingTour extends EditOnboardingTourBase
{
    protected static string $resource = OnboardingTourResource::class;
}
```

### `CreateOnboardingTour` — idéntica byte a byte en los 8

| Se borra | Se cambia |
|---|---|
| el `CreateOnboardingTour.php` del sistema | pasa a extender `Muni\Shared\Onboarding\Pages\CreateOnboardingTourBase` en vez de `Filament\Resources\Pages\CreateRecord` |

Sin acciones de cabecera propias —Filament ya pone "Crear" solo—, así que
esta base queda tan vacía como `Auditoria\ListActivitiesBase`.

```php
namespace App\Filament\Resources\OnboardingTourResource\Pages;

use App\Filament\Resources\OnboardingTourResource;
use Muni\Shared\Onboarding\Pages\CreateOnboardingTourBase;

class CreateOnboardingTour extends CreateOnboardingTourBase
{
    protected static string $resource = OnboardingTourResource::class;
}
```

En los 8 sistemas `$resource` **no** se fija en el paquete: cada uno tiene
su propio `OnboardingTourResource`, con su propio formulario y sus propias
columnas de tabla, y eso no es portable. `discapacidad-graneros`, a
diferencia de lo que pasó con `ActivityResource`, **no diverge en
namespace** acá: su `OnboardingTourResource` vive en el mismo
`App\Filament\Resources\...` que los demás siete, así que el patrón de
arriba se aplica sin cambios.

### Qué NO se cubrió, y por qué

- **El modelo `App\Models\OnboardingTour`, su Resource
  (`OnboardingTourResource.php`) y el `OnboardingToursSeeder` NO se mueven.**
  El modelo diverge de verdad entre sistemas (comparados por `md5sum`:
  5 de 8 idénticos, `discapacidad`/`licencias`/`feria` con distintos
  bloques de PHPDoc generados por `ide-helper`, sin diferencia funcional) y,
  sobre todo, es lo mismo que `LocalPersonaResolver`: depende de relaciones
  de dominio propias de cada sistema (`steps()`, `progress()`) que este
  paquete no puede poseer. El Resource tiene su propio formulario y tabla
  por sistema y tampoco es portable. Solo la Policy y las tres páginas —que
  no dependen de ningún campo ni relación del modelo— cruzan al paquete.
- **El `OnboardingController` del API, `config/onboarding.php` y las
  políticas de paso/progreso (`OnboardingStepPolicy`/`OnboardingProgressPolicy`)
  tampoco cruzan**, por el mismo motivo que el modelo: no hay clase propia
  que autoricen. Cada sistema que adopte esto sigue escribiendo su propio
  controlador local. Antes de escribirlo, conviene revisar
  `laravel-muni-onboarding` (repo local, archivado, sin remoto y sin
  adopción — ver el aviso al inicio de su README): ahí quedó documentado, con
  tests, el catálogo de arreglos que cada sistema había hecho por su lado y
  nunca había vuelto a los demás (escapado de XSS en `title`/`description`,
  el candado de IDOR para no resetear el progreso de otro usuario sin
  permiso, el permiso de administración leído de configuración en vez de un
  nombre de rol, el `user_id` nulo en `complete()`, el chequeo defensivo de
  `getRoleNames()`, y `mfa`+`throttle:60,1` en el middleware por defecto). No
  es código para copiar tal cual —cada sistema tiene su propio modelo y sus
  propias rutas—, es la lista de bugs ya resueltos que conviene no
  reintroducir al escribir el controlador propio.
- **`atencionvecino`** no tiene panel Filament (Laravel 12, Blade puro):
  no tiene el módulo de Onboarding y no es candidato.
