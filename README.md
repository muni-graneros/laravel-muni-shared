# laravel-muni-shared

Código compartido del **ecosistema municipal de Graneros** (licencias, discapacidad,
feria, personas). Elimina la duplicación byte-idéntica que hoy obliga a arreglar cada
bug N veces. Ver Frente 22 del ROADMAP de `plataforma-graneros`.

## Contenido

| Clase | Namespace | Estado |
|---|---|---|
| `Geocoder` | `Muni\Shared\Geocoder` | ✅ extraída (geocoding OSM, autocontenida) |
| `Coordenadas` | `Muni\Shared\Coordenadas` | ✅ extraída (parser lat/lng, autocontenida) |
| `RutHelper` | `Muni\Shared\RutHelper` | ✅ extraída (limpia/valida/formatea RUT chileno) |
| `RutValido` | `Muni\Shared\RutValido` | ✅ extraída (regla de validación Laravel, usa RutHelper) |
| `SystemNotification` | `Muni\Shared\SystemNotification` | ✅ extraída (base de notificaciones mail) |
| `MfaCodeNotification` | `Muni\Shared\MfaCodeNotification` | ✅ extraída (código MFA por correo, usa SystemNotification) |
| `Persona\PersonaDTO` | `Muni\Shared\Persona\PersonaDTO` | ✅ extraída (DTO neutro; `fromModel` vive en el resolver local de cada repo) |
| `Persona\PersonaResolverInterface` | `Muni\Shared\Persona\PersonaResolverInterface` | ✅ extraída (contrato sagrado, idéntico en todos) |
| `Persona\ApiPersonaResolver` | `Muni\Shared\Persona\ApiPersonaResolver` | ✅ extraída (cliente HTTP del maestro) |
| `LocalPersonaResolver` / `PersonaResolverConRespaldo` | — | quedan LOCALES: dependen del modelo `Persona` y sus relaciones de dominio (disc `discapacidades()`, feria `puestos()`). Implementan la interfaz compartida. |

## Instalación (repositorio privado por VCS)

En cada consumidor (`licencias`, `discapacidad`, `feria`, scaffold), en `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github-graneros:muni-graneros/laravel-muni-shared.git" }
    ]
}
```

```bash
composer require muni-graneros/laravel-muni-shared:^1.0
```

En CI, el runner ya autentica por la llave SSH `github-graneros` (o `composer config
github-oauth`). Durante desarrollo local se puede usar un repo `type: path`.

## Adopción de `Geocoder` (paso a paso, por repo)

1. `composer require muni-graneros/laravel-muni-shared`.
2. Reemplazar el `use App\Support\Geocoder;` por `use Muni\Shared\Geocoder;` en los
   archivos que lo usan (las llamadas `Geocoder::buscar(...)` no cambian).
3. Borrar `app/Support/Geocoder.php` local.
4. Correr `make test` + PHPStan; commit.

## Siguiente fase (PersonaResolver)

`ApiPersonaResolver`/`PersonaResolverConRespaldo` son byte-idénticos pero usan
`App\DTOs\PersonaDTO` y `App\Contracts\PersonaResolverInterface`, que **difieren** por
dominio entre disc y feria. Para extraerlos:

1. Definir en el paquete `Muni\Shared\Persona\PersonaDTO` + `PersonaResolverInterface`
   con los campos comunes, parametrizando lo específico.
2. Migrar cada consumidor a esos tipos.
3. Mover los resolvers HTTP + el registro de binding al `MuniSharedServiceProvider`.

## Desarrollo

```bash
composer install
./vendor/bin/pint --test
./vendor/bin/pest
```

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
| `TitularDeDatos` | Sí | Cómo se exporta, purga y anonimiza a una persona, y qué campos (`camposRectificables()`) puede corregir mediante el derecho de rectificación — no es un cheque en blanco sobre todo el registro |
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
