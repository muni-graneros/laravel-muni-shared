# Changelog

Los cambios que le importan a quien instala este paquete. Los 11 sistemas
municipales dependen de él, así que acá se anota **qué se rompe al subir** y qué
hay que hacer después de actualizar, no solo qué se agregó.

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado: [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

_Nada todavía._

## [1.21.0] - 2026-09-07


### Añadido
- `Onboarding\OnboardingTourPolicy`: la política del Resource de Onboarding del
  panel, byte a byte idéntica en los 8 sistemas del ecosistema con panel Filament
  (`atencionvecino` no tiene panel Filament). Mismo patrón que
  `Auditoria\RolePolicy`/`ActivityPolicy`: delega todo en los permisos que Filament
  Shield genera (`view_any_onboarding_tour`, `create_onboarding_tour`, …), sin
  hardcodear ningún nombre de rol. Diferencia real con esas dos: el modelo que
  autoriza (`App\Models\OnboardingTour`) es LOCAL de cada sistema —no un modelo de
  un vendor que este paquete pueda requerir—, así que la política tipa
  `Illuminate\Database\Eloquent\Model` en vez del modelo concreto.
- `Onboarding\Pages\ListOnboardingToursBase` / `EditOnboardingTourBase` /
  `CreateOnboardingTourBase`: las tres páginas del Resource de Onboarding.
  Ninguna fija `$resource` — cada sistema la extiende apuntando a SU
  `OnboardingTourResource` local, igual que `Auditoria\Pages\ListActivitiesBase`.
  A diferencia de esa clase (vacía, porque el listado de auditoría no traía
  ninguna acción de cabecera), `ListOnboardingToursBase` y
  `EditOnboardingTourBase` SÍ traen `getHeaderActions()`
  (`CreateAction`/`DeleteAction` respectivamente): esa lógica también estaba
  duplicada idéntica en los 8 sistemas, no solo el sitio de herencia.

### No portado, y por qué
- **El modelo `App\Models\OnboardingTour`, su `OnboardingTourResource.php` y el
  `OnboardingToursSeeder` NO se mueven.** El modelo diverge de verdad entre
  sistemas (5 de 8 idénticos por `md5sum`; los otros tres solo difieren en
  bloques de PHPDoc de `ide-helper`, sin diferencia funcional) y depende de
  relaciones de dominio propias de cada sistema (`steps()`, `progress()`) que
  este paquete no puede poseer — mismo motivo que `LocalPersonaResolver`. El
  Resource tiene su propio formulario y tabla por sistema.

### Notas de adopción
- Comparadas las 9 copias en disco (8 sistemas municipales + `atencionvecino`,
  que no tiene el módulo). `OnboardingTourPolicy` y `CreateOnboardingTour` son
  byte a byte idénticas en los 8. `ListOnboardingTours` diverge SOLO en estilo
  en `licencias-graneros` (`Actions\CreateAction::make()` contra
  `CreateAction::make()`, mismo comportamiento). `EditOnboardingTour` diverge
  igual en `licencias-graneros` y `feria-graneros` (`Actions\DeleteAction::make()`),
  la misma generación más vieja de Filament Shield ya documentada en
  `ActivityPolicy` (v1.20.0) — deuda de estilo, no una regla de negocio
  distinta. A diferencia de `ActivityResource`, `discapacidad-graneros` **no**
  diverge en namespace para `OnboardingTourResource`: los 8 sistemas usan el
  mismo `App\Filament\Resources\...`.
- Cada sistema que adopte esto borra su `app/Policies/OnboardingTourPolicy.php`
  y las tres páginas de `app/Filament/Resources/OnboardingTourResource/Pages/`,
  y apunta a las clases del paquete. El detalle por sistema está en el README,
  sección «Adopción de OnboardingTourPolicy y las páginas de Onboarding
  (§1.6)».
- `filament/filament` sigue como `suggest`, no como dependencia dura: el texto
  del `suggest` en `composer.json` ahora menciona también
  `Onboarding\Pages\*Base`.
- **Nada de lo agregado en esta versión necesita spatie/permission ni
  spatie/activitylog.** Verificado por reflexión: `OnboardingTourPolicy` solo
  tipa `Illuminate\Foundation\Auth\User` e `Illuminate\Database\Eloquent\Model`
  en la firma de sus métodos — ninguno de sus parámetros menciona `Filament` ni
  `Spatie` (hay una prueba que lo comprueba). Las tres páginas base sí importan
  clases de `filament/filament` (como ya hacía `ListActivitiesBase`), que sigue
  siendo `suggest`.

### Corregido

- Los candados `CredencialesDePlantilla` y `SegundoFactorEnProduccion` forzaban
  `app()['env'] = 'production'` para probarse y lo dejaban puesto. Contra SQLite
  no se notaba; contra MariaDB, la migración del caso siguiente entraba en la
  confirmación de `ConfirmableTrait` y ocho casos morían con un
  `BadMethodCallException` sobre el `OutputStyle` simulado que no menciona el
  entorno. Ahora restauran `testing` en un `afterEach`. Detectado por
  `composer test:mariadb`, que es para lo que existe.
## [1.20.0] - 2026-09-06

### Añadido
- `Auditoria\RolePolicy` y `Auditoria\ActivityPolicy`: las políticas de los Resources
  de Roles y de Auditoría del panel, que vivían copiadas byte a byte en 7 de los 8
  sistemas. Delegan en los permisos que genera Filament Shield (`view_any_role`,
  `create_role`, …), así que **no** hardcodean ningún nombre de rol: la diferencia
  entre `super_admin` y `administrador` la sigue resolviendo el `Gate::before` de
  cada sistema, fuera de la política.
- `Auditoria\Pages\ListActivitiesBase`: página base del listado de auditoría. No fija
  `$resource` — cada sistema la extiende apuntando a SU `ActivityResource` local.
- `Casts\EncryptedSeguro`: el cast de cifrado en reposo que tolera las filas heredadas
  en texto plano, copiado byte a byte en 7 de los 8 sistemas (todos menos
  `atencionvecino`, que no lo tenía). Se porta con el comportamiento EXACTO —cambiarlo
  dejaría ilegible lo que ya está escrito en siete bases de producción—. No reemplaza a
  `Privacidad\CifradoCast`: aquel truena ante un ciphertext manipulado, que es lo que
  se quiere para la evidencia ARCOP; este devuelve el valor crudo, que es lo que se
  quiere en una columna operativa a medio migrar.
- `Errores\ReporteDeErrores`: la comprobación de que las trazas de excepción van a un
  destino propio y no a un servicio extranjero (Ley 21.719), copiada byte a byte en 7
  de los 8 sistemas. La lista de destinos ajenos **no** es configurable a propósito:
  una lista que se puede acortar desde un `.env` no es un candado. `bootstrap/app.php`
  la sigue usando igual, cambiando solo el `use`.
- `Assets::versionado()` y la función global `asset_versionado()`: la URL de asset
  versionada por `mtime` que vivía en `app/Helpers/assets.php` en 7 de los 8 sistemas.
  Se exponen las DOS formas a propósito: la clase es lo que se prueba y lo que ve
  PHPStan; la función global es lo que ya escriben las plantillas Blade, así que
  adoptar el paquete no obliga a editar ninguna vista. La función conserva su guarda
  `function_exists`, de modo que el archivo del sistema y el del paquete conviven sin
  fatal mientras dura la adopción. El paquete pasa a declarar `autoload.files`; quien
  actualice desde una versión anterior con el autoloader ya generado necesita un
  `composer dump-autoload` (lo hace solo `composer update`).
- `Console\LimpiarDatosOperativosCommand`: el comando `env:clean-data` (mismo nombre
  de siempre, para no invalidar Makefiles ni runbooks), copiado byte a byte en 5 de los
  8 sistemas y en los dos scaffolds. Se registra solo. Dos cambios respecto de la copia
  local: la lista de tablas sale de `config/datos-operativos.php` (tag
  `datos-operativos-config`) en vez de una constante, y las claves foráneas se
  desactivan con `Schema::withoutForeignKeyConstraints()` en vez de
  `SET FOREIGN_KEY_CHECKS=0`, que era SQL de MySQL/MariaDB y hacía imposible probar el
  comando fuera de ese motor.
- `Seguridad\PermisosBajoOctane` + `Testing\AssertPermisosNoSeQuedanPegados`: el
  candado de que `permission.register_octane_reset_listener` esté en `true` donde corre
  Octane. Es lo que se extrae de `config/permission.php` **en lugar del archivo**: los 7
  sistemas que lo publicaron coinciden en todos los valores y ese booleano es el único
  que se aparta del default de spatie (`false`). Con el flag apagado, el registro de
  permisos sobrevive entre peticiones del mismo worker y a quien se le revoca un rol se
  lo sigue reconociendo. El trait es de PHPUnit y no necesita Pest.
- `Seguridad\SegundoFactorEnProduccion` + `Testing\AssertSegundoFactorNoSeRegala`:
  con `APP_ENV=production`, el segundo factor ni se apaga (`mfa.enabled` /
  `acceso.mfa.activa` en `false`) ni se regala (`mfa.show_code` /
  `acceso.mfa.mostrar_codigo` en `true`, que pinta el código en la propia pantalla de
  verificación). Lee la configuración que exista y **no escribe ninguna**.

### No portado, y por qué
- **`config/mfa.php` NO se mueve a este paquete.** Los siete archivos son cinco
  variantes distintas —`atencionvecino` describe un TOTP con `emisor` y sin
  `show_code`, `control-acceso` agrega el tope de códigos enviados— y, sobre todo, esa
  configuración ya tiene dueño: `laravel-muni-acceso` la expone como `acceso.mfa.*`
  leyendo las mismas variables de entorno (`MFA_ENABLED`, `MFA_SHOW_CODE`). Un `mfa.*`
  acá sería un segundo interruptor para la misma cerradura, que es peor que la copia.
  Lo que sí viaja es la regla que los siete comentan y ninguno hace cumplir, arriba.
- **`config/permission.php` tampoco.** Es el config que publica `spatie/laravel-permission`:
  traerlo ataría este paquete al esquema de configuración de spatie para custodiar un
  booleano. Viaja el booleano, como candado.

### Notas de adopción
- `filament/filament`, `spatie/laravel-permission` y `spatie/laravel-activitylog`
  entran como `suggest`, **no** como dependencia dura: `atencionvecino` consume este
  paquete (Privacidad, Persona, Correo) sin Filament y no debe instalarlos solo por
  actualizar.
- Cada sistema que adopte esto borra su `app/Policies/RolePolicy.php`,
  `app/Policies/ActivityPolicy.php` y su `ListActivities`, y apunta a las clases del
  paquete. El detalle por sistema —incluida la divergencia real de namespace de
  `discapacidad-graneros` y la deuda de estilo de `feria-graneros`— está en el
  README, sección «Adopción de RolePolicy, ActivityPolicy y ListActivitiesBase
  (§1.4)». Los 4 repos de KraftDo quedan fuera: son de otra entidad y no
  comparten paquete con la Municipalidad.
- **Corrección (07-09), sobre lo que decía esta misma entrada:** era falso que
  `personas-graneros` no requiera este paquete. Lo requiere desde julio, con el
  lock en v1.10.1 —anterior a que existiera `Auditoria\RolePolicy`—, y sus
  marcadores de Shield sin rellenar sí se arreglaron, en su propia copia local
  (`8fa2a24`): seis métodos (`forceDelete`, `forceDeleteAny`, `restore`,
  `restoreAny`, `replicate`, `reorder`) comparaban contra `'{{ ForceDelete }}'`
  y compañía, así que denegaban a todos salvo al `super_admin` que salva el
  `Gate::before`.
- **Trampa al adoptar, que costó descubrir:** `bezhansalleh/filament-shield`
  registra la policy de Roles con una ruta **hardcodeada**
  (`Gate::policy(Utils::getRoleModel(), 'App\Policies\RolePolicy')`) y solo si
  el archivo existe en disco. O sea que el sistema que adopte la clase del
  paquete **no puede borrar su `app/Policies/RolePolicy.php`**: tiene que
  dejarlo como fachada vacía que extienda la del paquete. Borrarlo deja la
  policy sin registrar y todo pasa a decidirlo el `Gate::before`.
- **Nada de lo agregado en esta versión necesita Filament ni Pest.** Verificado por
  reflexión con el autoloader del paquete: las siete clases y los dos traits nuevos solo
  dependen de `Illuminate\*`, `PHPUnit\Framework\Assert` y del propio `Muni\Shared`.
  `PermisosBajoOctane` menciona `Laravel\Octane\Octane` únicamente dentro de un
  `class_exists()`, y `laravel/octane` no es dependencia del paquete: hay una prueba que
  lo comprueba.
- El paso a paso de qué borra y qué configura cada uno de los ocho sistemas está en el
  README, sección «Adopción de lo pequeño (§1.5)». Dos avisos que no se pueden saltear:
  tras borrar `app/Helpers/assets.php` hay que correr `composer dump-autoload`, y el
  candado `Muni\Candados\Candados\ErroresNoSalenDelPais` necesita que se le pase
  `clase: \Muni\Shared\Errores\ReporteDeErrores::class` o seguirá buscando la clase
  borrada.

## [1.19.0] - 2026-09-05

### Añadido
- `Seguridad\CredencialesDePlantilla`: aborta el arranque en producción si la contraseña
  de la base de datos o la de cifrado de los respaldos siguen siendo las del
  `.env.example` público del scaffold. Portada desde
  `App\Support\CredencialesDePlantilla` de `scaffold-laravel-filament-pwa`, donde era
  la única protección — los ocho sistemas generados a partir de él no tenían la clase.
  Se engancha sola en `MuniSharedServiceProvider::boot()`: instalar el paquete alcanza,
  sin agregar ninguna línea al `AppServiceProvider` del sistema. La lista de valores
  vigilados es configurable (`config/credenciales-de-plantilla.php`, tag
  `credenciales-de-plantilla-config`), con los del scaffold como valor por omisión.

### Qué se rompe al subir
- **Un sistema que hoy esté en producción con `DB_PASSWORD=sistema_pass` o con la
  contraseña de respaldos del ejemplo dejará de arrancar** al actualizar a esta
  versión. Es a propósito: hoy arranca, y esa es exactamente la puerta abierta que
  la guarda cierra. Antes de desplegar esta versión conviene comprobar en cada
  servidor que esos dos valores ya no son los del ejemplo; si alguno lo es, el
  arreglo es cambiarlo, no saltarse la guarda. El mensaje del error dice qué
  variable cambiar y nunca imprime el valor configurado.
- No afecta a desarrollo (solo actúa con `APP_ENV=production`) ni al build de la
  imagen (no lanza mientras no haya `APP_KEY`, que es como arranca
  `package:discover` durante `composer install`).

## [1.18.0] - 2026-09-03

### Añadido
- `AssertEnvExampleCompleto` y `ContratoDeEnvExample`: el paquete trae su propio
  candado para que un `.env.example` no se desalinee de lo que `config()` lee.
  Los sistemas lo usan con un test de una línea.
- PHPStan nivel 8 y `composer audit` bloqueante en CI; Dependabot semanal contra
  `develop` (nunca contra `main`: el flujo del ecosistema es develop → main y
  una PR contra `main` se lo saltaría).

### Corregido
- El texto libre del módulo de Privacidad queda **cifrado en reposo** (Ley
  21.719). Requiere correr `php artisan privacidad:cifrar-texto-libre` una vez
  después de migrar; la migración crea las columnas, el comando traslada lo que
  ya estaba escrito en claro.
- `Bloqueos::vigente()` comparaba `titular_id` (varchar) contra un entero de PHP,
  así que MariaDB descartaba el índice del morph y escaneaba la tabla entera.
- El RUT y la dirección del vecino dejaban de estar en claro en los registros y
  en GlitchTip: el mensaje de excepción de Guzzle incluye la URI completa, y la
  URI llevaba el RUT.

## [1.17.1] - 2026-09-02
- El acceso federado deja de emitir la cookie de «Recordarme»: con SSO, esa
  cookie permite volver a entrar sin pasar por el proveedor de identidad.

## [1.17.0] - 2026-09-01
- `Geocoder` distingue «no pude preguntar» de «no existe» y deja de cachear los
  fallos, que envenenaban la caché durante horas ante una caída momentánea.

## [1.16.0] - 2026-08-25
- Módulo Privacidad: si procede entregar la copia, deja de ser una regla
  escondida en el motor del ciclo.

---

Antes de la 1.16.0 no se llevó changelog. El historial de `git log` es la
referencia para esas versiones.
