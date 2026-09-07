# CLAUDE.md — laravel-muni-shared

**Entidad: Municipalidad de Graneros.** Paquete Composer privado
`muni-graneros/laravel-muni-shared`, versión publicada **v1.21.0**.

## Qué es

Código compartido del ecosistema municipal de Graneros: `Geocoder`, `RutHelper`,
notificaciones base, resolvers de `Persona` contra el maestro, el módulo
**Privacidad** (Ley 21.719: solicitudes ARCOP, bitácora, retención, brechas),
correo vía Graph, SSO Keycloak y `Seguridad\CredencialesDePlantilla`.

## Qué NO es

- No tiene identidad visual (eso vive en `laravel-muni-ui`).
- `LocalPersonaResolver` / `PersonaResolverConRespaldo` son LOCALES a cada
  consumidor a propósito: dependen del modelo `Persona` de cada sistema.
- Filament, `spatie/laravel-activitylog` y `spatie/laravel-permission` son
  `suggest`, no requeridos: `atencionvecino` consume este paquete en Blade puro
  sin Filament. No los agregues como `require` duro.

## Comandos reales

```bash
composer install
composer test          # pest, SQLite en memoria
composer test:mariadb   # tools/pest-mariadb.sh — contra MariaDB desechable
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
composer audit --no-dev --format=plain
```

El CI (`.github/workflows/ci.yml`) corre Pint, PHPStan, Pest y `composer audit`
bloqueante.

## Cómo se prueba

`composer test` alcanza para desarrollo normal. **Antes de publicar una
versión es obligatorio correr también `composer test:mariadb`**: la producción
del ecosistema es MariaDB y la suite en SQLite ya escondió un defecto crítico
(ver CHANGELOG, sección Privacidad/inmutabilidad).

## Cómo se publica

1. Cerrar la sección de la versión en `CHANGELOG.md` **antes** de taguear (no
   dejarla como "Sin publicar" y cerrarla después: ya pasó dos veces y generó
   un tag con el CHANGELOG desincronizado).
2. Bump de versión, commit, `git tag vX.Y.Z`.
3. **El push del tag lo hace César, salvo que pida lo contrario en la sesión.**
   Los consumidores resuelven por `type: vcs` + `no-api: true`: `composer update`
   no ve la versión nueva hasta que el tag esté empujado al remoto, así que un
   tag sin pushear es un tag que no existe para nadie.

## Consumidores a actualizar tras publicar

12 consumidores. Al subir una versión con cambios que rompen algo (leer la
sección "Qué se rompe al subir" del CHANGELOG), avisar y subir el constraint
en:

- `^1.18`: web-graneros, licencias-graneros, seguridad-graneros,
  feria-graneros, discapacidad-graneros, control-acceso-graneros,
  rrhh-graneros, scaffold-laravel-filament-pwa.
- `^1.10`: atencionvecino, `plataforma-graneros/personas-graneros`.
- `^1.16`: laravel-arcop-panel (require), laravel-muni-ui (require-dev).

## Qué NO hacer aquí

- No mover `LocalPersonaResolver` ni nada que dependa del modelo `Persona` de
  un consumidor específico a este paquete.
- No subir Filament/activitylog/permission de `suggest` a `require`.
- No taguear sin cerrar el CHANGELOG primero.
- No hacer `git push` ni `git merge`: los hace César.
- No leer `.env`/`.env.*` (salvo `.env.example`).
