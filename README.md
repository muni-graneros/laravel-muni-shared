# laravel-muni-shared

Código compartido del **ecosistema municipal de Graneros**: lo que estaba
copiado byte a byte en ocho sistemas y obligaba a arreglar cada defecto ocho
veces. Versión publicada: **`v1.21.0`**.

Lo consumen hoy 12 repos: `web-graneros`, `licencias-graneros`,
`seguridad-graneros`, `feria-graneros`, `discapacidad-graneros`,
`control-acceso-graneros`, `rrhh-graneros` y `scaffold-laravel-filament-pwa`
(`^1.18`); `atencionvecino` y `personas-graneros` (`^1.10`); `laravel-arcop-panel`
y `laravel-muni-ui` (`^1.16`).

## Instalación

Repositorio **privado**, sin Packagist. En el `composer.json` del sistema:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/muni-graneros/laravel-muni-shared.git",
            "no-api": true
        }
    ]
}
```

```bash
composer require muni-graneros/laravel-muni-shared:^1.21
```

Dos detalles que no son cosméticos:

- **`"no-api": true`.** Sin eso Composer resuelve por la API de GitHub y pide un
  token personal.
- **La URL va en `https://`, no con el alias SSH `git@github-graneros:`.** El
  alias funciona en el equipo de César —vive en su `~/.ssh/config`— y **rompe el
  CI**, donde `composer install` muere con «Could not resolve hostname
  github-graneros» antes de instalar nada. Con `https://`, el `insteadOf` local
  la reescribe a SSH igual y el CI se autentica con su propia credencial.

Nunca pegar un token en `composer.json`, en el `.env` ni en un ejemplo: el
acceso sale de la credencial del equipo o del runner.

El constraint `^1.21` es `>=1.21 <2.0` y sí sube de minor —esto es 1.x—. Ojo si
venís de `laravel-muni-candados`, que está en 0.x: **ahí** el caret no sube de
minor y hay que editar el constraint a mano.

Requiere PHP ^8.3 e `illuminate/*` ^11, ^12 o ^13 (no fija Laravel entero, para
que `atencionvecino` en Laravel 12 pueda consumirlo). `firebase/php-jwt` ^7.1 y
`laravel/prompts` entran como dependencias duras.

**Filament, `spatie/laravel-activitylog` y `spatie/laravel-permission` son
`suggest`, nunca `require`**: `atencionvecino` es Blade puro y consume
`Privacidad`, `Persona` y `Correo` sin instalar ninguno de los tres. Solo las
páginas y policies de panel (`Auditoria\*`, `Onboarding\*`) los necesitan.

## Qué expone

Todo cuelga de `Muni\Shared\`.

| Namespace | Qué hay | Depende de |
|---|---|---|
| (raíz) | `Geocoder` + `GeocoderNoDisponible`, `Coordenadas`, `RutHelper`, `RutValido`, `Assets`, `SystemNotification`, `MfaCodeNotification` | — |
| `helpers.php` | `asset_versionado()` global (con guarda `function_exists`) | — |
| `Casts\` | `EncryptedSeguro`: cifrado en reposo que tolera filas heredadas en claro | — |
| `Errores\` | `ReporteDeErrores`: las trazas no salen del país (Ley 21.719) | — |
| `Seguridad\` | `CredencialesDePlantilla` (ver abajo), `PermisosBajoOctane`, `SegundoFactorEnProduccion` | — |
| `Testing\` | `ContratoDeEnvExample` + `AssertEnvExampleCompleto`, `AssertPermisosNoSeQuedanPegados`, `AssertSegundoFactorNoSeRegala`. **PHPUnit puro, no Pest** | — |
| `Persona\` | `PersonaDTO`, `PersonaResolverInterface`, `ApiPersonaResolver`, `MaestroPersonaService` (autocompletar por RUT contra el maestro), `WriteThrough\{SincronizarAlMaestro, Resincronizar, VerificarSincronizacion}` | — |
| `Sso\` | `KeycloakSsoController` (OIDC Authorization Code contra la cuenta municipal) y `SsoClaims` | `firebase/php-jwt` |
| `Correo\` | `TransporteGraph` (envío por Microsoft Graph) y `ConfiguracionDeCorreo` | — |
| `Health\` | `SaludEcosistema`: base de datos, Redis/cola y alcance del maestro en formato normalizado, ampliable con `conExtra()` | — |
| `Privacidad\` | El módulo de la Ley 21.719 completo: ARCOP, bitácora, consentimientos, retención, brechas, bloqueos. **Ver [`docs/privacidad/modulo.md`](docs/privacidad/modulo.md)** | — |
| `Auditoria\` | `RolePolicy`, `ActivityPolicy`, `Pages\ListActivitiesBase` | Filament + spatie (`suggest`) |
| `Onboarding\` | `OnboardingTourPolicy`, `Pages\{List,Edit,Create}OnboardingTourBase` | Filament (`suggest`) |
| `Console\` | `muni:docs`, `correo:probar`, `correo:configurar`, `env:clean-data` | — |

**Queda LOCAL a cada sistema, a propósito:** `LocalPersonaResolver` y
`PersonaResolverConRespaldo` (dependen del modelo `Persona` y de sus relaciones
de dominio: `discapacidades()` en disc, `puestos()` en feria), y el modelo
`OnboardingTour` con su Resource y su controlador (mismo motivo). Implementan
los contratos de este paquete.

## Qué hace el provider al instalarse

`Muni\Shared\MuniSharedServiceProvider` se autodescubre y **cablea solo**, sin
que el sistema escriba una línea:

- **Comprueba las credenciales de plantilla** (`CredencialesDePlantilla::comprobar()`),
  lo primero de todo.
- **Carga las migraciones** del módulo Privacidad (`loadMigrationsFrom`, no se
  publican): actualizar el paquete propaga el esquema con un `migrate`, sin un
  paso por repo que alguien va a olvidar.
- **Fusiona cuatro archivos de configuración**: el mailer `graph` dentro de
  `mail.mailers.graph`, más `privacidad`, `credenciales-de-plantilla` y
  `datos-operativos`. Ningún sistema toca su `config/mail.php`: alcanza con
  `MAIL_MAILER=graph` y las credenciales en el `.env`.
- **Enlaza dos contratos por defecto**, sustituibles:
  `RegistroDeEvidencia → BitacoraEnBaseDeDatos` y
  `ResuelveTitularesVencidos → NingunTitularVencido`.
- **Registra el transporte de correo `graph`** (Laravel resuelve transportes por
  nombre al enviar, no al leer la configuración).
- **Agenda la retención** con `withoutOverlapping()` + `onOneServer()` **solo si**
  el sistema declaró `privacidad.retencion.hora`. Sin esa clave no se agenda
  nada: instalar un paquete no puede poner a correr un destructivo en ocho
  sistemas.
- **Registra los comandos** (solo en consola).

### Lo publicable

```bash
php artisan vendor:publish --tag=privacidad-config                 # config/privacidad.php
php artisan vendor:publish --tag=credenciales-de-plantilla-config  # config/credenciales-de-plantilla.php
php artisan vendor:publish --tag=datos-operativos-config           # config/datos-operativos.php
php artisan vendor:publish --tag=privacidad-stubs                  # docs/privacidad/ del sistema
```

Las migraciones **no** se publican: se cargan.

### Comandos

```bash
php artisan muni:docs [--output=docs/DOCUMENTACION_TECNICA.md] [--print]
php artisan correo:configurar
php artisan correo:probar
php artisan env:clean-data
php artisan privacidad:rat [--json]
php artisan privacidad:diagnostico
php artisan privacidad:aplicar-retencion [--ejecutar]
php artisan privacidad:cifrar-texto-libre [--ejecutar]
```

Los dos con `--ejecutar` toman un candado (`Cache::lock`) y fallan con código
distinto de cero si ya hay otra corrida en curso; la simulación no lo toma.
Detalle en [`docs/privacidad/modulo.md`](docs/privacidad/modulo.md).

## Guarda de credenciales de plantilla (`Seguridad\CredencialesDePlantilla`)

Portada desde el scaffold, donde hasta el 2026-09-05 era la ÚNICA protección del
ecosistema: los ocho sistemas generados a partir de él no tenían la clase y su
`.env.example` seguía trayendo la misma contraseña de base de datos
(`sistema_pass`) y la misma de cifrado de respaldos
(`cambiame-por-algo-seguro-en-produccion`), las dos publicadas en un repositorio
que cualquiera puede leer. Un sistema desplegado sin cambiarlas arrancaba igual,
en silencio.

**Adopción: cero pasos, a propósito.** `MuniSharedServiceProvider::boot()` la
llama sola. La alternativa —una línea que cada sistema pega en su
`AppServiceProvider`, como hacía el scaffold— es exactamente el paso que esta
migración encontró saltado en los ocho. Es el mismo precedente que
`agendarRetencion()`: «es el paso que nadie escribe», y ahí la obligación legal
de suprimir dependía de que alguien se acordara.

El costo del cableado automático es que corre siempre, así que las dos
salvaguardas no son opcionales:

- **Solo actúa en producción** (`app()->environment('production')`). En
  cualquier otro entorno no hace nada, ni siquiera si encuentra un valor de
  plantilla: todo el desarrollo del ecosistema arranca con `sistema_pass`.
- **No lanza durante `composer install` / `package:discover`.** Ahí Laravel
  arranca sin `.env` —y asume `APP_ENV=production`, su valor por defecto— para
  descubrir los paquetes; sin `app.key` no hay instalación real que proteger, y
  lanzar ahí tumbaría el propio `composer install`, en el CI y en el build de la
  imagen, con un error que habla de la base de datos y despista.
- **El mensaje dice qué variable del `.env` cambiar** y **nunca el valor real
  configurado**: solo menciona el valor de plantilla, que ya es público. Un
  secreto de producción no aparece en una excepción que puede terminar en un log
  o en un panel de errores.

Un sistema con credenciales de plantilla propias publica el config y **extiende**
la lista, para no perder la vigilancia sobre las del scaffold:

```php
// config/credenciales-de-plantilla.php
use Muni\Shared\Seguridad\CredencialesDePlantilla;

return [
    'valores' => [
        ...CredencialesDePlantilla::POR_OMISION,
        [
            'queEs' => 'contraseña del panel de reportes',
            'config' => 'reportes.password',
            'valorDePlantilla' => 'reportes_demo',
            'variableEnv' => 'REPORTES_PASSWORD',
        ],
    ],
];
```

`laravel-muni-candados` vigila que esta guarda esté de verdad enganchada
(`Candados::guardaDeCredencialesDePlantilla()`, en `todos()`): exige que el
sistema requiera este paquete **en versión instalada ≥ 1.19.0** —donde nació la
guarda— y que no le apague el auto-descubrimiento al proveedor.

## `.env.example` completo (`Testing\ContratoDeEnvExample`)

Cada sistema arrastraba un `.env.example` desalineado de `config/`: la auditoría
del ecosistema (2026-08-30) contó 134 claves fuera en licencias, 130 en
discapacidad, 125 en feria. Laravel ignora en silencio la variable de un `env()`
cuyo archivo de config no la declara —no hay error, corre con el valor de
fábrica— así que nadie se entera hasta que falla en producción algo que en local
nunca se probó.

`ContratoDeEnvExample` compara, con el tokenizador de PHP (no una regex: ver su
docblock), las claves que `config/*.php` lee de verdad con `env(...)` contra las
que `.env.example` declara —comentadas o no—. Cada adoptante suma un test de
tres líneas:

```php
// tests/Feature/EnvExampleCompletoTest.php
use Muni\Shared\Testing\AssertEnvExampleCompleto;

uses(AssertEnvExampleCompleto::class);

it('.env.example documenta todo lo que config/ lee', function () {
    $this->assertEnvExampleCompleto(); // usa config_path() y base_path('.env.example')
});
```

Sin argumentos toma las rutas reales del sistema que corre el test. El mensaje
de fallo lista las claves faltantes, así que arreglarlo es copiar la línea que
falta, comentada si es una bandera opcional.

## `Geocoder`: `buscar()` o `buscarEstricto()` (desde 1.17.0)

`Geocoder::buscar()` devuelve `null` tanto si Nominatim no conoce la dirección
como si no se le pudo preguntar (red caída, error del proveedor, límite local de
peticiones). Para un formulario da igual; para un **job en cola** no: un corte
del proveedor dejaba el registro sin ubicar en silencio, sin reintento ni rastro
en `failed_jobs`.

```php
use Muni\Shared\Geocoder;
use Muni\Shared\GeocoderNoDisponible;

// En un job: null = «no existe», sigue; la excepción se deja subir y la cola reintenta.
$r = Geocoder::buscarEstricto($direccion); // @throws GeocoderNoDisponible
```

`buscar()` no cambió de contrato: es `buscarEstricto()` con la excepción
capturada. Ninguno de los dos cachea fallos; `sugerencias()` tampoco cachea la
lista vacía que deja un fallo de red (antes la guardaba 12 horas).

## Desarrollo

```bash
composer install
composer test           # Pest, SQLite en memoria
composer test:mariadb   # la misma suite contra un mariadb:11 desechable
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
composer audit --no-dev --format=plain
```

### Antes de publicar una versión: correr la suite en MariaDB

La suite corre en SQLite en memoria y la producción del ecosistema corre en
MariaDB. Esa diferencia no es teórica: `Bitacora::desvincular()` —la
anonimización, o sea la retención completa— falló **siempre** en MariaDB durante
cuatro rondas de trabajo con la suite en verde, porque Laravel compila
`cast(? as json)` para el UPDATE de una ruta JSON y MariaDB no soporta
`CAST AS JSON`. Lo vio la primera corrida contra el motor real, no las
revisiones de código.

Lo mismo corre en CI (job `Pest sobre MariaDB`). Correrlo local sigue siendo el
paso obligatorio antes de etiquetar: **si la CI del repo está caída por
facturación, este comando es la única corrida que existe.**

El CHANGELOG se cierra **antes** de taguear, no después: ya pasó dos veces y
dejó un tag con el CHANGELOG desincronizado. El push y el tag los hace César, y
un tag sin empujar no existe para nadie: los consumidores resuelven por
`type: vcs`, así que `composer update` no ve la versión hasta que el tag está en
el remoto.

## Documentación larga

- [`docs/privacidad/modulo.md`](docs/privacidad/modulo.md) — **el manual del
  módulo de la Ley 21.719**: ciclo ARCOP, consentimiento por texto acreditado,
  régimen de NNA y representación, supresión y write-through al maestro,
  bloqueos, retención, límites declarados y comandos. Es lo primero que hay que
  leer antes de adoptar `Privacidad`.
- [`docs/adopcion-ecosistema.md`](docs/adopcion-ecosistema.md) — qué archivo
  local borra cada sistema municipal y qué configura, por pieza extraída
  (`Auditoria`, `EncryptedSeguro`, `ReporteDeErrores`, `asset_versionado()`,
  `env:clean-data`, `config/permission.php`, `config/mfa.php`, `Onboarding`), y
  qué quedó fuera de la extracción y por qué.
- [`docs/patron-panel-municipal.md`](docs/patron-panel-municipal.md) — el patrón
  de panel del ecosistema.
- `docs/privacidad/decisiones-del-municipio.md`,
  `docs/privacidad/hallazgos-del-ecosistema.md` y las dos verificaciones de
  adopción en discapacidad.
- `docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md` — los huecos
  abiertos del módulo y los `GRANT` que hay que aplicar en el motor.
