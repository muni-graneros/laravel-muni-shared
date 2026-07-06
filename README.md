# laravel-muni-shared

Código compartido del **ecosistema municipal de Graneros** (licencias, discapacidad,
feria, personas). Elimina la duplicación byte-idéntica que hoy obliga a arreglar cada
bug N veces. Ver Frente 22 del ROADMAP de `plataforma-graneros`.

## Contenido

| Clase | Namespace | Estado |
|---|---|---|
| `Geocoder` | `Muni\Shared\Geocoder` | ✅ extraída (autocontenida: solo facades de Laravel) |
| `MfaCodeNotification` | — | ⏳ acoplada a `SystemNotification`; extraer tras normalizar |
| `ApiPersonaResolver` / `PersonaResolverConRespaldo` | — | ⏳ dependen de `PersonaDTO`/`PersonaResolverInterface` que **difieren** entre repos → normalizar contrato primero |

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
