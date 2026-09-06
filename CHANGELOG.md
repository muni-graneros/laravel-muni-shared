# Changelog

Los cambios que le importan a quien instala este paquete. Los 11 sistemas
municipales dependen de él, así que acá se anota **qué se rompe al subir** y qué
hay que hacer después de actualizar, no solo qué se agregó.

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado: [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

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

### Notas de adopción
- `filament/filament`, `spatie/laravel-permission` y `spatie/laravel-activitylog`
  entran como `suggest`, **no** como dependencia dura: `atencionvecino` consume este
  paquete (Privacidad, Persona, Correo) sin Filament y no debe instalarlos solo por
  actualizar.
- Cada sistema que adopte esto borra su `app/Policies/RolePolicy.php`,
  `app/Policies/ActivityPolicy.php` y su `ListActivities`, y apunta a las clases del
  paquete.

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
