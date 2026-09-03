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
| `Testing\ContratoDeEnvExample` / `AssertEnvExampleCompleto` | `Muni\Shared\Testing\*` | ✅ extraída (compara `config/` contra `.env.example`, ver más abajo) |
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

### `buscar()` o `buscarEstricto()` (desde 1.17.0)

`Geocoder::buscar()` devuelve `null` tanto si Nominatim no conoce la dirección como si
no se le pudo preguntar (red caída, error del proveedor, límite local de peticiones).
Para un formulario da igual; para un **job en cola** no: un corte del proveedor dejaba
el registro sin ubicar en silencio, sin reintento ni rastro en `failed_jobs`.

```php
use Muni\Shared\Geocoder;
use Muni\Shared\GeocoderNoDisponible;

// En un job: null = «no existe», sigue; la excepción se deja subir y la cola reintenta.
$r = Geocoder::buscarEstricto($direccion); // @throws GeocoderNoDisponible
```

`buscar()` no cambió de contrato: es `buscarEstricto()` con la excepción capturada.
Ninguno de los dos cachea fallos; `sugerencias()` tampoco cachea la lista vacía que deja
un fallo de red (antes la guardaba 12 horas).

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

### Antes de publicar una versión: correr la suite en MariaDB

```bash
composer test:mariadb      # levanta un mariadb:11 desechable y lo borra al salir
```

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

## `.env.example` completo (`Testing\ContratoDeEnvExample`)

Cada sistema arrastraba un `.env.example` desalineado de `config/`: la auditoría
del ecosistema (2026-08-30) contó 134 claves fuera en licencias, 130 en
discapacidad, 125 en feria. Laravel ignora en silencio la variable de un
`env()` cuyo archivo de config no la declara —no hay error, corre con el valor
de fábrica— así que nadie se entera hasta que falla en producción algo que en
local nunca se probó.

`Muni\Shared\Testing\ContratoDeEnvExample` compara, con el tokenizador de PHP
(no una regex: ver su docblock), las claves que `config/*.php` lee de verdad
con `env(...)` contra las que `.env.example` declara —comentadas o no—. Cada
adoptante suma un test de tres líneas:

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
falta —comentada si es una bandera opcional, como ya hacen `CSP_ENABLED` u
`OCR_ENABLED`—.

## Módulo Privacidad (Ley 21.719)

Cubre el registro de actividades de tratamiento, el consentimiento por
finalidad, los derechos ARCOP con control de plazo, la retención con supresión
efectiva y el registro de brechas.

### El núcleo del ciclo ARCOP (`Muni\Shared\Privacidad\Ciclo`)

Las reglas del ciclo ARCOP que ANTES vivían dentro del panel de Filament
(`SolicitudResource`, en `laravel-muni-ui`). Están acá porque tienen consecuencia
legal y hay más de un panel: el de Filament y el de Blade
(`laravel-arcop-panel`). Dos implementaciones de la misma regla divergen, y
divergir acá es certificarle por escrito a un vecino algo que no ocurrió.

Ninguna devuelve presentación: entregan un enum o un objeto de hechos, y cada
panel elige el color y la redacción.

| Clase | Qué decide |
|---|---|
| `PlazoLegal` / `EstadoDePlazo` | El semáforo de plazo. `PlazoLegal::DIAS_POR_VENCER` es el umbral único, y `Solicitud::scopePorVencer()` lo toma de ahí. Una solicitud resuelta no tiene plazo. |
| `SeparacionDeFunciones` | Si quien va a resolver es quien recibió. Es aviso, no prohibición. Quién resuelve llega por parámetro, no de `auth()`. |
| `ResultadosDisponibles` | Con qué resultados se puede cerrar a mano cada tipo. Una rectificación y una supresión solo se pueden **rechazar** a mano: acogerlas es ejecutar la acción que escribe y propaga. |
| `EtiquetaDeTitular` | Cómo se nombra al titular, distinguiendo caso anonimizado, titular huérfano y titular vivo. |
| `AlcanceDelCese` | Qué deja de hacer el sistema con un bloqueo vigente, y qué le pasa al bloqueo según cómo se resolvió (acoger una **oposición** lo vuelve definitivo, no lo levanta). Sin declarar, dice que no se declaró. |
| `PreviaDeSupresion` | Hasta dónde llega el derecho **antes** de tocar nada. No escribe. |
| `ResumenDeSupresion` | Los hechos de una supresión: total o parcial, archivos borrados, rutas sin archivo, y si el maestro de personas aceptó la baja. Suprimir local ≠ salir del ecosistema. |

El contrato `BuscaTitulares` también vive acá
(`Muni\Shared\Privacidad\Contratos\BuscaTitulares`): se movió desde
`Muni\Ui\Filament\Privacidad\Contratos`, donde un panel sin Filament no lo
podía implementar. En `muni-ui` queda un alias deprecado que lo extiende, así que
un adoptante que ya lo implementaba no se rompe.

Quien implemente el buscador tiene dos obligaciones que el módulo no puede
imponer por código: **mínimo de caracteres y resultados acotados**. Ese buscador
es la superficie por donde se puede enumerar el padrón de un municipio.

### Titulares con clave no numérica (desde v1.15.0)

`titular_id` es **texto** (64) en las tablas del módulo, no un entero. Nació
entero porque los primeros sistemas identifican a la persona por un id
autoincremental; `atencionvecino` usa el RUT como clave primaria y MariaDB
truncaba «11111111-1» a 11111111 al escribirlo: la solicitud quedaba apuntando a
un titular que no era el que vino al mesón.

Un número sigue cabiendo, así que **ningún sistema que ya guarde ids numéricos
cambia de comportamiento**. Lo único que cambia es el tipo que devuelve PHP:

```php
$solicitud->titular_id === $persona->id   // antes true, ahora FALSE ('1' !== 1)
$solicitud->titular_id == $persona->id    // true
```

Si un sistema compara con `===` contra un id numérico, hay que castear. En el
propio módulo no queda ninguna comparación así.

La migración repone los guardias de inmutabilidad después de cambiar el tipo:
en SQLite un `change()` reconstruye la tabla y **se lleva los triggers**, o sea
que dejaría la evidencia legal sin protección en silencio. MariaDB no tiene ese
problema.

### Texto libre cifrado en reposo (desde v1.18.0)

Las columnas de prosa del módulo van **cifradas con la APP_KEY del sistema**
(`CifradoCast`, el `encrypted` de Laravel con tolerancia a las filas viejas):

| Tabla | Columnas |
|---|---|
| `privacidad_solicitudes` | `detalle`, `fundamento_resolucion`, `verificacion_identidad` |
| `privacidad_bloqueos` | `motivo`, `levantado_motivo` |

Son lo que dicta el ciudadano («mi RUT es…, vivo en…», en discapacidad un
diagnóstico), el RUN con que acreditó su identidad, la respuesta escrita y los
motivos que dicta un funcionario nombrando a la persona o a un familiar. Las
tablas las comparten los ocho sistemas y estaban en claro.

Qué cambia para un adoptante:

- **La APP_KEY pasa a ser parte de la evidencia.** Rotarla sin recifrar deja
  ilegible el expediente ARCOP: `Solicitud->detalle` lanza `DecryptException`.
  Respaldar la clave junto con la base (Vaultwarden), y si hay que rotarla,
  hacerlo con `APP_PREVIOUS_KEYS` de Laravel, que el cast respeta.
- **Las filas escritas antes de esta versión se siguen leyendo en claro**, sin
  fallar: el cast reconoce un valor cifrado por la forma de su payload y lo
  que no la tiene lo devuelve tal cual. Un payload cifrado que no valida su
  MAC (otra clave, fila manipulada) sí truena, como debe. Para cifrar lo que
  ya está escrito:

  ```bash
  php artisan privacidad:cifrar-texto-libre            # simulación: cuántas filas
  php artisan privacidad:cifrar-texto-libre --ejecutar
  ```

  Es idempotente: lo ya cifrado no se toca. Correrlo una vez después de
  migrar; no está agendado ni es automático a propósito, porque reescribe
  filas con la clave del sistema.
- **La migración cambia `verificacion_identidad` de JSON a LONGTEXT.** MariaDB
  ≥ 10.4.3 le agrega solo `CHECK (json_valid())` a las columnas JSON, y un
  valor cifrado no es JSON: la primera escritura fallaría en producción con la
  suite en verde (SQLite no tipa). La migración comprueba que la restricción no
  haya sobrevivido y falla con un mensaje claro si sobrevivió.
- **Los `update()` masivos no pasan por los casts.** `Bloqueo::query()
  ->update(['motivo' => …])` escribe en claro con el cast declarado y sin que
  nada avise. Dentro del paquete se cifra con `CifradoCast::cifrar()`; un
  adoptante que escriba esas columnas por builder tiene que hacer lo mismo, y
  el que las lea por `DB::table()` tiene que descifrar con
  `CifradoCast::descifrar()`. Buscar con `LIKE` sobre esas columnas ya no
  funciona: es el precio del cifrado.
- **Tope útil:** las columnas TEXT admiten 65.535 bytes y el cifrado agrega
  ~40 % más ~200 bytes, así que el texto más largo que cabe ronda los 46 KB.

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
PRIVACIDAD_PLAZO_NOTIFICACION_BRECHA_DIAS=3
PRIVACIDAD_DISCO_EVIDENCIA=local
PRIVACIDAD_RESPONSABLE="I. Municipalidad de Graneros"
PRIVACIDAD_CONTACTO=privacidad@municipalidadgraneros.cl
PRIVACIDAD_DELEGADO=
PRIVACIDAD_RETENCION_HORA=03:30
```

`PRIVACIDAD_RETENCION_HORA` es **paso obligatorio de adopción**, sin default.
Con ella, el paquete agenda `privacidad:aplicar-retencion --ejecutar` a esa hora
todos los días, con `withoutOverlapping()` y `onOneServer()`. Sin ella no se
agenda nada, y eso también es a propósito: instalar un paquete no puede poner a
correr un destructivo en ocho sistemas. Lo que se midió en la adopción real es
el otro lado del mismo problema: el módulo quedó instalado, migrado, sembrado y
con los contratos enlazados, y `schedule:list` no listaba ninguno de sus
comandos — la obligación legal de suprimir dependía de que alguien se acordara
de tipear el comando. Comprobar con `php artisan schedule:list` después de
adoptar.

`PRIVACIDAD_DISCO_EVIDENCIA` es **paso obligatorio de adopción**, sin default:
tiene que nombrar el disco donde ESTE sistema guarda los documentos cuyas
rutas le pasa al módulo (`Solicitudes::acoger($respuestaPath)` y
`Consentimientos::otorgar(['evidencia_path' => …])`). El módulo nunca los
escribió —solo los borra al anonimizar—. Dejarla en blanco o apuntada a un
disco que no existe **hace fallar la anonimización** apenas encuentra un
documento que borrar (`DiscoEvidenciaNoConfigurado`): es a propósito, para no
repetir el defecto que tuvo este ejemplo hasta 2026-08-16, cuando la clave
tenía un default ('local') y una declaración vacía o ausente caía ahí en
silencio. Lo que SIGUE sin poder detectar el módulo por su cuenta es un
nombre de disco que resuelve pero no es donde viven los documentos: ese caso
no truena, y se ve mirando la constancia `retencion.constancia` de la
corrida: `archivos_no_encontrados` alto con `archivos_suprimidos` en cero.

### Lo que cada sistema debe aportar

| Contrato | Obligatorio | Qué resuelve |
|---|---|---|
| `TitularDeDatos` | Sí | Cómo se exporta, purga y anonimiza a una persona, qué campos (`camposRectificables()`) puede corregir mediante el derecho de rectificación —no es un cheque en blanco sobre todo el registro— y su fecha de nacimiento (`fechaNacimientoTitular()`), de la que depende el régimen reforzado de NNA |
| `ResuelveTitularesVencidos` | Sí | Desde cuándo se trata a un titular bajo cada finalidad. Decía «solo si hay retención» y ya no alcanza: `Supresiones` lo consulta para saber si el plazo de una finalidad por función legal todavía corre para el titular que pide la supresión. Con el enlace por defecto (`NingunTitularVencido`) nadie está vencido nunca, así que un sistema sin resolvedor **no puede acoger ninguna supresión a petición** sobre finalidades por función legal |
| `VerificadorIdentidad` | Por convención | Cómo se acredita que el solicitante es el titular. El paquete **no lo resuelve del contenedor**: `Solicitudes::registrar()` recibe un `ResultadoVerificacion` ya construido. Es el código que llama —la acción del panel, el mesón— el que debe verificar con él antes de registrar; implementarlo y no usarlo no protege nada |
| `PropagaSupresion` | **Sí** (una de las dos formas) | Qué debe pasar en el maestro de personas cuando este sistema suprime a un titular. Sin enlace, `AplicarRetencion` y `Supresiones` **se niegan a ejecutar** —la misma exigencia, en una sola clase (`SupresionEnElMaestro`), para que las dos no puedan divergir—. Un sistema que no es modelo de lectura del maestro lo declara enlazando `SupresionSoloLocal`. Mismos requisitos que `PropagaRectificacion`: síncrono, `rechazada()` o lanzar significan «no se propagó», y entonces no se destruye nada local |
| `PropagaRectificacion` | Solo si es modelo de lectura del maestro | Que la rectificación no la pise la próxima sincronización. **Debe ser síncrono**: tiene que conocer la respuesta del maestro antes de devolver. Despachar un job en cola y devolver `aceptada()` no es una implementación válida —informa éxito antes de que el maestro haya visto nada—. `rechazada()` o lanzar significan lo mismo: no se propagó |

Los dos contratos de propagación devuelven un `ResultadoDePropagacion`, no un
`bool`. Ver [más abajo](#propagar-al-maestro-tiene-tres-respuestas-no-dos) por
qué, y qué respuesta corresponde en cada caso.
| `RegistroDeEvidencia` | No | Sustituir la bitácora propia por la del sistema |

Además, cada sistema siembra sus finalidades: es donde declara qué trata, con
qué base y por cuánto tiempo.

### Acreditar QUÉ texto leyó el titular

El módulo no muestra nada: cada sistema pinta el texto en su formulario. Lo que
el módulo guarda es a qué **fila exacta** de `privacidad_textos` se consintió,
en `privacidad_consentimientos.texto_id`. Hay que pasársela:

```php
// Al pintar el formulario:
$texto = app(Textos::class)->vigente('consentimiento_difusion');

// Al guardarlo — con la MISMA fila que se pintó (o su id, que es lo que
// vuelve del request):
app(Consentimientos::class)->otorgar($persona, $finalidad, MedioDeConsentimiento::Web, [
    'texto' => $texto,                       // o 'texto' => $request->integer('texto_id')
    'evidencia_path' => $rutaDelPdfFirmado,  // opcional
    'ip' => $request->ip(),
]);
```

**Si no se pasa `texto`, la fila queda con `texto_id` en null**: el
consentimiento existe y es válido, pero no puede acreditar qué versión leyó la
persona. Es el estado de los consentimientos en papel anteriores a esta tabla, y
el módulo no lo inventa por nadie. Un sistema que nunca pase la opción tendrá
todos sus consentimientos así, que es exactamente lo que una fiscalización
pregunta.

Por qué la **fila** y no el código del texto: entre que el formulario se
renderiza y que el funcionario lo guarda, otro funcionario puede publicar una
versión nueva. Resolver el código al escribir dejaba el consentimiento apuntando
a un texto que el titular nunca vio —prueba falsa, peor que no tener ninguna—.
Por eso `otorgar()` **rechaza** con `OpcionInvalida` las opciones viejas
`codigo_texto` y `version_texto` en vez de ignorarlas, y rechaza con
`TextoNoPublicado` un `texto` que no existe o que es de otro sistema, en vez de
guardar null en silencio. Un texto con la vigencia ya cerrada **sí** se acepta:
es justamente el caso de arriba.

`version_texto` queda como columna muerta: sigue en la tabla por las filas
anteriores a `texto_id`, el módulo ya no la escribe y no la acepta. Dos registros
del mismo hecho, uno de ellos sin nada que lo respalde, terminan en que se
consulta el equivocado.

`Informaciones::registrar()` admite la misma opción, por la misma ventana:

```php
app(Informaciones::class)->registrar($persona, 'aviso_recoleccion', MedioDeConsentimiento::Web, [
    'texto' => $texto,
]);
```

Sin la opción, resuelve el vigente al escribir (cómodo cuando mostrar y sellar
ocurren en la misma petición; con la ventana abierta si no).

### Quien actúa por otro tiene que acreditarlo

Cuando `otorgado_por` (consentimiento) o el `Solicitante` (solicitud ARCOP) no es
el propio titular, hay que acompañar el documento que acredita la representación
—certificado de nacimiento, sentencia de cuidado personal, mandato—:

```php
app(Consentimientos::class)->otorgar($nna, $finalidad, MedioDeConsentimiento::FirmaPapel, [
    'otorgado_por' => Solicitante::RepresentanteLegal,
    'acreditacion_path' => 'acreditaciones/certificado-nacimiento-11111111.pdf',
]);

app(Solicitudes::class)->registrar(
    $nna, TipoDeSolicitud::Acceso, $detalle, $verificacion,
    Solicitante::RepresentanteLegal,
    'acreditaciones/certificado-nacimiento-11111111.pdf',
);
```

Sin esa ruta se rechaza con `RepresentacionNoAcreditada`. Antes bastaba con
elegir la opción en un desplegable, y la fila afirmaba una representación que
nadie podía mostrar.

Alcance exacto de la exigencia: el módulo comprueba que **haya una ruta no
vacía**. No comprueba que el archivo exista, ni que el documento diga lo que dice
ser, ni quién es el representante. La identidad del representante **no se
guarda** en estas tablas a propósito: las columnas que el barrido de
anonimización conserva se conservan por ser categóricas, y un nombre ahí rompe
ese argumento. Vive en el documento, que se borra del disco al anonimizar como
el resto (`acreditacion_path` está en el barrido).

### Régimen reforzado de niños, niñas y adolescentes

Rige igual en los dos caminos, y esa simetría es el punto: durante un ciclo el
consentimiento tuvo régimen de edad y los derechos ARCOP no, con lo que el mismo
niño de 10 años no podía consentir que le publicaran una foto y sí podía
llevarse la copia íntegra de su registro de discapacidad —datos de salud—, que
le acogieran una supresión que lo saca de un registro comunal del que su familia
puede depender, o presentar una rectificación que se propaga al maestro federado
de personas.

- `Consentimientos::otorgar()` no acepta el consentimiento de un menor de edad.
- `Solicitudes::registrar()` no acepta **ninguno de los cinco derechos** ejercido
  por un menor de edad por sí mismo.

En ambos lo ejerce su representante legal (`Solicitante::RepresentanteLegal`),
acreditado con el documento (ver la sección anterior). Ni el propio titular ni un
apoderado —un menor no puede otorgar mandato—. La negativa es
`RepresentacionRequerida`.

**Ningún derecho ARCOP está exceptuado**, y es una decisión deliberada, no un
olvido: la frontera fina —qué puede ejercer personalmente un adolescente, sobre
qué tipo de dato— exige una lectura de la ley y su reglamento que el paquete no
puede zanjar por los ocho sistemas que lo adoptan, y el lado elegido es el que no
entrega datos de salud de un menor a quien se presenta solo. La consecuencia —un
adolescente de 17 no puede pedir su propia copia sin su representante— está
anotada como pendiente en
`docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md`.

**Una fecha de nacimiento `null` no significa adulto**, significa que la edad no
está acreditada, y las dos entradas la rechazan con `EdadNoAcreditada`. Es el
mismo criterio de `Brecha::riesgo_alto`, donde `null` es «todavía sin evaluar».
Un sistema que devuelva siempre `null` en `fechaNacimientoTitular()` **no podrá
otorgar ningún consentimiento ni registrar ninguna solicitud ARCOP**: eso es
intencional, y se resuelve acreditando la fecha, no relajando la comprobación.
Ojo con lo segundo al planificar la adopción —registrar la solicitud es lo que
hace correr el plazo legal de respuesta, así que la negativa no puede quedar en
que el mesón despache a la persona: hay que acreditar la fecha y registrarla.

`EdadNoAcreditada` y `RepresentacionRequerida` son excepciones distintas porque
piden acciones distintas del funcionario: la primera, pedir el documento con la
fecha de nacimiento; la segunda, que venga el representante legal. Atraparlas
juntas devuelve al operador el consejo equivocado.

La comprobación solo corre para titulares que implementan `TitularDeDatos`, que
es a quienes se les puede preguntar la fecha. Y solo cubre los caminos de
`Consentimientos::otorgar()` y `Solicitudes::registrar()`: un adoptante que cree
filas de esas tablas a mano se la salta.

La mayoría de edad se calcula comparando **fechas de calendario** en
`config('app.timezone')` —la del municipio—, no instantes: quien devuelva la
fecha desde el maestro federado como `DateTimeImmutable` en UTC obtiene el mismo
resultado que quien la devuelve casteada por Eloquent. Lo que el módulo no puede
detectar es una fecha de nacimiento guardada con hora en otra zona: devolverla
como fecha (cast `date`) es lo que la mantiene bien.

La finalidad puede además cerrarse a los NNA con `admite_nna = false` en el RAT,
y entonces se rechaza con `FinalidadInvalida` aunque firme el representante
legal. El default es `true`, para no apagar retroactivamente finalidades que ya
vienen tratando menores.

### Atender un acceso o una portabilidad

```php
$datos = app(ExportacionDeDatos::class)->paraSolicitud($solicitud);
```

`paraSolicitud()` es la entrada a usar desde el panel: toma el titular de la
solicitud —que ya pasó por la verificación de identidad al registrarse—, rechaza
los tipos que no dan derecho a la copia y deja la entrega en `privacidad_bitacora`.
`paraTitular()` no verifica nada ni registra nada: cablearla a una acción que
recibe un id del request es un IDOR sin rastro.

### Atender una supresión: `Supresiones`, y por qué no es incondicional

```php
$resultado = app(Supresiones::class)->aplicar($solicitud, $fundamento, $respuestaPath);
```

Acoger una solicitud de supresión con `Solicitudes::acoger()` a secas sella la
fila como resuelta y **no suprime nada**. Eso no es un cabo suelto: es el peor
estado posible: la solicitud queda cerrada, el plazo legal deja de correr y el
municipio tiene constancia escrita de haber cumplido un derecho que no cumplió.
La entrada correcta es `Supresiones::aplicar()`.

Lo que ese servicio hace, y no es «borrar todo»: la supresión por retención es
unilateral —venció el plazo que el propio municipio declaró—, pero la supresión
a petición **es legítimamente rechazable**. Si el dato se trata por función
legal u obligación legal con norma habilitante y su plazo de conservación sigue
corriendo, el derecho no procede sobre esa finalidad. De ahí los tres
desenlaces:

| Situación | Qué hace | Estado de la solicitud |
|---|---|---|
| Ninguna finalidad obliga a conservar | Purga los sensibles, anonimiza, corta el vínculo con las cinco tablas del módulo y borra los documentos del disco | Acogida |
| Alguna obliga y alguna no | **No destruye nada**; deja un bloqueo definitivo por cada finalidad en que sí procede | Acogida parcial |
| Todas obligan a conservar | Lanza `SupresionNoProcede`, con la norma y el plazo en el mensaje | Queda **en trámite** |

**Con un RAT realista, la respuesta habitual es la del medio.** No es el caso de
borde: basta UNA finalidad fundada en el consentimiento, o una por función legal
sin `plazo_retencion_meses` declarado, o una cuyo plazo ya venció para este
titular, para que algo cese y la evaluación caiga en parcial. Montar el caso «no
procede» en los tests del módulo exigió desactivar finalidades del RAT sembrado.
O sea: lo que el mesón va a tener que saber explicar la mayoría de las veces
**no** es «sus datos fueron borrados» ni «no procede», sino «dejamos de
tratarlos para esto y esto, y estos otros se conservan por esta norma hasta esta
fecha». Un panel que solo contemple los dos extremos va a estar mal la mayor
parte del tiempo.

La tercera fila tampoco es un rechazo: `SupresionNoProcede` deja la solicitud
**en trámite** a propósito. Rechazar es una resolución fundada que le responde a
un ciudadano y que firma una persona; escribirla desde el módulo sería inventar
el fundamento de un acto administrativo.

Tres cosas que hay que saber antes de cablearlo a un panel:

- **La acogida parcial es un cese, no un borrado parcial.** El módulo no sabe
  —no puede saber— qué columna del sistema adoptante pertenece a qué finalidad,
  así que borrar «lo de la finalidad que cesa» sería adivinar sobre datos que la
  norma manda conservar. Lo que deja son `privacidad_bloqueos` vigentes; que el
  tratamiento cese de verdad **depende de que el sistema los consulte**
  (`Bloqueos::vigente($titular, $finalidad)`), igual que cualquier otro bloqueo.
- **El módulo no rechaza por su cuenta.** Cuando no procede, truena y deja la
  solicitud en trámite: rechazar es una resolución fundada que le responde a un
  ciudadano y que firma una persona. `Supresiones::evaluar($titular)` calcula lo
  mismo sin efectos, para que el panel pueda mostrarlo antes y el funcionario
  cite la norma en vez de inventarla.
- **El documento de respuesta se borra en la misma operación** cuando la
  supresión es total. Lleva los datos de quien pidió que los borraran;
  entregárselo al titular es del procedimiento, conservarlo contradiría la
  supresión.

Qué cuenta como «obliga a conservar»: finalidad activa + base de licitud que
exige norma habilitante + plazo declarado + ese plazo todavía corriendo para
**ese** titular, según el `ResuelveTitularesVencidos` del sistema. Con el enlace
por defecto (`NingunTitularVencido`) nadie está vencido nunca, así que un
sistema que no implementó el resolvedor no va a poder suprimir a nadie a
petición. Una finalidad por función legal **sin plazo declarado no impide**: en
el RAT ese null es ambiguo y `AplicarRetencion` ya lo lee como «no conserva a
nadie»; está anotado en el spec de pendientes.

### Un bloqueo no detiene nada: el candado lo escribe el sistema adoptante

Es lo primero que hay que entender antes de cablear el módulo a un panel, y por
eso está acá y no en una nota al pie. `Bloqueos::vigente()` e
`impideCorregir()` son **consultas**. No hay scope global, no hay interceptor de
consultas, no hay nada que se meta entre el sistema adoptante y sus propias
tablas. El módulo escribe en `privacidad_bloqueos` que el tratamiento de una
persona está suspendido; **quien tiene que preguntarlo antes de usar el dato es
el adoptante**, en cada lugar donde lo usa.

Dicho sin suavizarlo: el estado por defecto de cualquier sistema que instale
este paquete es que **su panel acoja una oposición, la selle como resuelta,
escriba el bloqueo, y el sistema siga tratando el dato exactamente igual que
antes**. La solicitud queda cerrada, el plazo legal deja de correr, y el
municipio tiene constancia escrita de un cese que no ocurrió.

No es hipotético. `discapacidad-graneros` —el primer adoptante— estuvo
exactamente así hasta que construyó su propio candado
(`App\Privacidad\CeseDeTratamiento`, seis puntos de guarda en su código): hasta
ese día su panel le prometía por escrito a un vecino un cese que no ocurría.
Los otros siete sistemas empiezan en ese mismo punto.

**Paso obligatorio de adopción: el mapeo tratamiento → finalidad.** El módulo
no lo puede hacer por nadie —no sabe qué pantalla, qué exportación a CSV, qué
job por cron, qué endpoint ni qué notificación de otro repo pertenece a qué
finalidad del RAT—. Hay que escribirlo a mano, sistema por sistema:

1. Listar cada punto donde el sistema **usa** los datos del titular: pantallas
   que los exhiben, listados y buscadores, exportaciones, informes, cruces con
   otras bases, envíos de correo o SMS, jobs y comandos por cron, endpoints de
   API, decisiones automatizadas.
2. Para cada uno, decir a qué **finalidad declarada en el RAT** corresponde.
   Si no corresponde a ninguna, el hallazgo no es del bloqueo: es que el sistema
   está tratando datos para algo que nunca declaró.
3. Poner en cada uno la guarda: `Bloqueos::vigente($titular, $finalidad)` antes
   de usar el dato, e `impideCorregir($titular, $finalidad)` antes de
   escribirlo. Las dos devuelven `true` cuando hay bloqueo, o sea cuando **no**
   se puede.
4. Probarlo con un titular bloqueado de verdad, en el navegador, no en un test
   unitario del mapeo.

Las dos preguntas no son la misma, y confundirlas tiene una consecuencia
concreta:

| Método | Devuelve `true` cuando | Preguntarlo antes de |
|---|---|---|
| `vigente($titular, $finalidad)` | hay una suspensión que alcanza a esa finalidad | **usar** el dato: exhibirlo, exportarlo, cruzarlo, notificar, decidir |
| `impideCorregir($titular, $finalidad)` | lo mismo, salvo el bloqueo preventivo de una rectificación en trámite | **corregir** el dato: escribir la rectificación que el titular pidió |

Las dos devuelven `true` cuando **no** se puede: son «¿está bloqueado?», no
«¿puedo?».

Registrar una rectificación pone un bloqueo preventivo **sin finalidad**, o sea
sobre todas. Un adoptante que consultara `vigente()` antes de aplicar la
corrección se frenaría a sí mismo el cumplimiento del derecho que está
tramitando. `impideCorregir()` exceptúa exactamente ese caso —el bloqueo
preventivo de una rectificación en trámite— y nada más: no exceptúa la
oposición en trámite, ni los bloqueos definitivos, ni los puestos a mano, ni el
de una rectificación ya resuelta.

**Un bloqueo alcanza al sistema donde se presentó, no al ecosistema entero.**
`privacidad_bloqueos` es una tabla compartida por los ocho sistemas y
`vigente()` filtra por `privacidad.sistema`. Se eligió así porque cesar de más
le corta a un vecino una prestación que la ley obliga a dar, sobre finalidades
que nunca discutió y sin dejar rastro de haberse decidido; y porque qué
finalidad de licencias corresponde a cuál de discapacidad no hay dato en la
tabla del que deducirlo. Lo que el módulo sí ofrece es que no quede invisible:
`sistemasConBloqueoVigente($titular)` dice en qué otras ventanillas hay una
suspensión vigente sobre la misma persona, para que alguien decida qué
corresponde acá. Que el derecho ejercido ante el municipio opere en todas sus
ventanillas es **procedimiento municipal**: hoy la vía es registrar la solicitud
en cada sistema donde el titular quiera que opere.

**Un bloqueo se levanta con `levantar($bloqueo, $motivo)`**, incluso si no nació
de una solicitud (uno puesto a mano, o el que crea `volverDefinitivos()` con la
bandera apagada). Exige decir por qué —reanudar es una decisión sobre alguien
que había obtenido el cese— y un sistema no puede levantar el bloqueo de otro.

### Resolver una oposición: acogerla NO levanta el bloqueo

Registrar una rectificación o una oposición pone un bloqueo preventivo mientras
se resuelve (`PRIVACIDAD_BLOQUEAR_DURANTE_SOLICITUD`). Qué pasa con ese bloqueo
al resolver depende de si el titular obtuvo el cese, y no es uniforme:

| Resolución | El bloqueo |
|---|---|
| Rechazada (cualquier tipo) | Se levanta: el tratamiento sigue, con fundamento |
| Rectificación acogida | Se levanta: corregido el dato, se reanuda |
| Oposición acogida o acogida parcial | **Se vuelve definitivo**: el tratamiento cesa |
| Supresión acogida parcialmente | Se mantienen los que puso `Supresiones` |

Acoger una oposición además **crea** el bloqueo si no había ninguno (bandera
apagada): sin eso, acogerla no tendría ningún efecto sobre el tratamiento. El
cese queda con el evento `bloqueo.definitivo` en la bitácora.

### Retención: se suprime a quien vencieron TODAS sus finalidades

`privacidad:aplicar-retencion` no anonimiza a quien venció en una finalidad:
anonimiza a quien venció en **todas** las finalidades activas con plazo del
sistema. Está dicho acá porque la versión anterior hacía lo otro y el efecto era
silencioso: recorría finalidad por finalidad y anonimizaba de inmediato, así que
el plazo más corto del RAT se llevaba puestos a los demás. En la corrida real,
`agenda_citas` (24 meses) anonimizó a **11.517 personas** que `registro_comunal`
(120 meses) tenía que conservar; los plazos de 60 y 120 declarados a la autoridad
no operaban.

Lo que eso le exige al `ResuelveTitularesVencidos` de cada sistema: `vencidos()`
tiene que devolver, para cada finalidad, **a quien esa finalidad ya no
necesita** — tanto al que cumplió el plazo como al que esa finalidad nunca
alcanzó. Un resolvedor que devuelva solo a los que tienen historia bajo esa
finalidad (p. ej. solo quienes tuvieron citas, para `agenda_citas`) deja a los
demás fuera de la intersección y **no se los anonimiza nunca**. Falla del lado
seguro —conserva de más— pero es conservación indebida, y hay que revisarlo al
escribir el resolvedor.

La pantalla de confirmación (la simulación) muestra ahora tres números, porque
la tabla por finalidad sola era engañosa: sumaba 50.041 «titulares» sobre 20.027
personas sin decir que los conjuntos se superponen.

```
Los conjuntos por finalidad se superponen: la suma de la tabla NO es un total de personas.
Personas distintas alcanzadas: 20027
Se suprimirían: 2483 — solo quienes vencieron en TODAS las finalidades con plazo.
```

La evidencia de cada supresión (`retencion.aplicada`) y la constancia de la
corrida (`retencion.constancia`) llevan **todas** las finalidades consideradas
con su plazo, no la que venció primero.

La constancia se escribe **por lote** (`PRIVACIDAD_RETENCION_LOTE`, 100 por
defecto) y es **acumulada**: cada una lleva el total de la corrida hasta ese
punto, todas comparten un token `corrida`, y la última dice `completa: true`.
Se lee tomando la última de cada `corrida`; sumarlas cuenta dos veces a las
mismas personas. Existe así porque el `finally` que la escribía al cierre
protege contra excepciones pero no contra que maten el proceso: la corrida real
murió por timeout a los 10 minutos con 10.131 personas anonimizadas y **cero
constancias**. Si ninguna constancia de un `corrida` dice `completa`, esa corrida
no terminó.

### Supresión y write-through al maestro (paso obligatorio de adopción)

Esto es lo que apareció al correr la retención contra un maestro de personas
real, y no lo veía ninguna suite: **anonimizar localmente daba de alta la
identidad de la persona en el registro federado**.

La mecánica, medida en la base del maestro (120 filas creadas en 60
anonimizaciones): `purgarDatosSensibles()` y `anonimizar()` terminan en
`->save()`; el sistema adoptante tiene un observador `Persona::saved` que
despacha el write-through; el maestro hace upsert por RUT. Resultado: el primer
`save()` empuja la persona **todavía íntegra** —o sea, la retención la da de
alta en el maestro justo antes de borrarla acá— y el segundo crea una persona
nueva `ANON-{id}`. La identidad real queda viva y consultable por RUT desde los
otros siete sistemas.

El paquete cierra lo que puede cerrar solo:

- `AplicarRetencion` corre la purga y la anonimización dentro de
  `SupresionEnCurso`, una marca de proceso.
- `SincronizarAlMaestro` —el transporte del ecosistema, del que heredan los ocho
  sistemas— estampa esa marca en el job al construirlo y descarta el envío al
  ejecutarlo, aunque el observador lo haya despachado igual.
- `SincronizarAlMaestro` descarta además cualquier envío cuyo `nro_documento`
  empiece con el centinela `ANON-`. Esto cierra la **segunda** puerta, que la
  marca de proceso no alcanza: el cron `personas:resincronizar` compara
  `updated_at > sincronizado_maestro_at`, y como la anonimización mueve
  `updated_at`, quince minutos después re-despachaba a la persona ya anonimizada
  y creaba el `ANON-{id}` igual. Un sistema que anonimice con otro centinela
  tiene que sobrescribir `suprimido(array $payload)`.

Lo que **cada sistema** tiene que hacer, y sin esto la adopción no está completa:

1. **Guardar el observador.** El write-through no debe despacharse durante una
   supresión:

   ```php
   Persona::saved(function (Persona $p): void {
       if (SupresionEnCurso::activa()) {
           return; // la retención no sincroniza: propaga la supresión aparte
       }

       $actor = auth()->user();
       SincronizarPersonaAlMaestro::dispatch($p->id, $actor?->email, $actor?->name);
   });
   ```

   La guardia del job ya lo cubre; ésta evita además llenar la cola y el log de
   jobs descartados, y es la que protege a cualquier otro observador que el
   sistema tenga colgando de `saved`.

2. **Enlazar `PropagaSupresion`.** Es lo que decide qué le llega al maestro
   cuando alguien ejerce su derecho de supresión. Las dos respuestas obvias ya
   se descartaron con evidencia: no mandar nada deja la identidad viva en el
   registro federado (y el municipio certificando una supresión que no ocurrió),
   y mandar la persona anonimizada crea un `ANON-{id}` nuevo sin tocar la real.
   La tercera —la del contrato— es propagar la **supresión del registro que ya
   existe**, identificado por el documento que el titular tenía ANTES de
   anonimizar. El endpoint del maestro **existe**
   (`DELETE /servicios/v1/personas/{rut}` en `personas-graneros`, ya usado por
   `discapacidad-graneros`), así que enlazar `SupresionSoloLocal` pudiendo
   propagar de verdad es declarar una supresión más chica que la que el
   ecosistema puede hacer. Lo que sigue abierto es qué hace ese endpoint: ver
   los límites declarados más abajo.

   ```php
   // AppServiceProvider::register()
   $this->app->bind(PropagaSupresion::class, SuprimirEnMaestro::class);
   ```

### Propagar al maestro tiene tres respuestas, no dos

`PropagaSupresion::propagar()` y `PropagaRectificacion::propagar()` devuelven un
`ResultadoDePropagacion`, que se construye con uno de tres constructores:

| Respuesta | Qué significa | Qué hace el módulo |
|---|---|---|
| `ResultadoDePropagacion::aceptada()` | El maestro recibió el cambio y lo aceptó | Sigue: destruye lo local, o acoge la rectificación |
| `ResultadoDePropagacion::rechazada()` | El maestro contestó que no | Aborta. No se destruye nada y la solicitud queda en trámite. Lanzar hace lo mismo |
| `ResultadoDePropagacion::noCorrespondia($motivo)` | **No se habló con el maestro, y estuvo bien no hablarle** | Sigue, pero deja escrito que nadie habló con el registro federado |

Antes eran dos —`bool`— y esa era la mentira: un sistema que en tiempo de
ejecución decide no contactar al maestro (el ambiente no lo tiene configurado,
este titular nunca estuvo allá) no tenía forma de decirlo sin devolver `true`.
Y ya se estaba diciendo: en `discapacidad-graneros`,
`PropagaSupresionAlMaestro` devolvía `true` sin contactar a nadie cuando el
driver de la API no era `http`, así que el módulo destruía el dato local
creyendo que el maestro había aceptado la supresión.

Tres cosas que hay que saber para usar el tercer estado:

- **El motivo es obligatorio y no puede ir en blanco** (`PropagacionInvalida` si
  lo va). Sin él, «no correspondía» sería el mismo cheque en blanco con otro
  nombre y la evidencia no podría responder por qué no se propagó.
- **El motivo es una afirmación sobre el SISTEMA, nunca sobre el titular.**
  Viaja a `privacidad_bitacora.datos`, que no está cifrada, y ahí rige la misma
  invariante que en el resto del módulo. «El maestro no está configurado en este
  ambiente» sí; el nombre o el RUT de una persona, no. El módulo no lo puede
  comprobar: el texto lo escribe el adoptante.
- **No se puede confundir con el éxito en ningún lado**, y eso es a propósito:
  `retencion.aplicada`, `supresion.aplicada` y `rectificacion.aplicada` escriben
  el estado y el motivo en `datos.propagacion`;
  `ResultadoDeSupresion::$propagacion` se lo entrega al panel que redacta la
  respuesta al titular (y va en `null` en la acogida parcial, porque ahí no se
  destruyó nada y no había nada que propagar); y la corrida de retención agrega
  `sin_propagar_al_maestro` en su constancia, que el comando imprime. La
  pregunta «¿este sistema propaga sus supresiones?» se contesta mirando la
  evidencia, no averiguando qué tenía enlazado ese día.

`SupresionSoloLocal` —la declaración de entrada del sistema que no es modelo de
lectura del maestro— también responde `noCorrespondia()`, y no `aceptada()`: ahí
tampoco hay ningún maestro que haya aceptado nada.

### Límites declarados (leer antes de adoptar)

Lo que el módulo **no** hace, dicho acá y no en un scratch que se borra. El
detalle y el resto de los huecos abiertos están en
`docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md`.

- **No hace cesar ningún tratamiento.** Escribe bloqueos y los sabe consultar;
  el candado que hace que el dato deje de usarse lo escribe cada sistema
  adoptante, con su propio mapeo tratamiento → finalidad. Sin ese candado, el
  panel promete por escrito un cese que no ocurre. Es el límite más caro de
  todos y tiene su [propia sección](#un-bloqueo-no-detiene-nada-el-candado-lo-escribe-el-sistema-adoptante).
- **No comprueba que se haya hablado con el maestro de personas.** La
  propagación puede contestar `noCorrespondia()` y la supresión local sigue
  adelante: quien sabe si hay a quién hablarle es el sistema, no el paquete. Lo
  que el módulo garantiza es que la diferencia quede escrita y llegue a quien
  redacta la respuesta al titular, no que el registro federado se haya enterado.
- **No comprueba el motivo de un `noCorrespondia()`.** Es texto del adoptante y
  termina en la bitácora sin cifrar; que no lleve datos del titular es
  responsabilidad de quien lo escribe.
- **Los códigos de texto nombran finalidades, no grupos de personas.**
  `texto_id` sobrevive a la anonimización —el texto no identifica a nadie— pero
  eso vale mientras `codigo` sea `aviso_recoleccion` o
  `consentimiento_difusion`. Un código por barrio, por programa o por cohorte
  (`consentimiento_ayudas_tecnicas_villa_esperanza`) **particiona las filas
  huérfanas**: en un grupo chico, el solo puntero al texto vuelve a distinguir a
  la persona dentro del conjunto anonimizado. Si hace falta esa granularidad, va
  como columna del sistema adoptante, no como código de texto.
- **Los regímenes de edad y de representación cubren los métodos del módulo**,
  no la tabla: un `INSERT` a mano en `privacidad_consentimientos` o
  `privacidad_solicitudes` se los salta.
- **La acreditación de representación es una ruta, no una verificación**: el
  módulo no abre el documento ni comprueba que exista en el disco.
- **Un sistema sin fechas de nacimiento queda bloqueado** para consentir y para
  registrar solicitudes ARCOP (ver arriba: es intencional, y tiene consecuencia
  operativa sobre el plazo legal).
- **Quien nació un 29 de febrero** cumple los 18 el 1 de marzo según este módulo.
  Es el lado conservador —lo trata como NNA un día más— y no una certeza
  jurídica: el art. 48 del Código Civil admite leerse como el 28.
- **La marca de supresión no impide ningún envío por sí sola**: impide los de
  quien la consulta. Hoy la consultan `SincronizarAlMaestro` y el observador del
  sistema adoptante. Un sistema que empuje al maestro por otro camino —un
  servicio propio, un `DB::table()` contra la base del maestro— vuelve a tener el
  defecto completo, y el módulo no puede detectarlo.
- **Que la supresión llegue al maestro depende del `PropagaSupresion` de cada
  sistema.** El endpoint **existe** (`DELETE /servicios/v1/personas/{rut}` en
  `personas-graneros`, ya usado por `discapacidad-graneros`); acá decía que no,
  y era falso: enlazar `SupresionSoloLocal` pudiendo propagar de verdad es
  declarar una supresión más chica que la que el ecosistema puede hacer. Lo que
  sí sigue abierto es lo que ese endpoint hace: `baja()` es un soft delete, o
  sea que el maestro **oculta** al suprimido —la identidad completa sigue en la
  fila— y el `upsert()` de cualquiera de los otros sistemas lo **revive** con un
  `restore()` silencioso. Lo que consigue hoy el ecosistema es destrucción local
  + baja lógica reversible en el maestro. Sirve para dejar de tratar el dato acá;
  no alcanza para certificarle una supresión a la Agencia. El detalle y qué hay
  que arreglar en `personas-graneros` están al principio del spec de pendientes.
- **Vale igual para la supresión a petición** (`Supresiones`), que usa el mismo
  contrato: acoger una supresión total propaga la baja al maestro con los mismos
  límites de arriba.
- **La acogida parcial de una supresión no borra nada**: deja bloqueos. Si el
  sistema adoptante no consulta `Bloqueos::vigente()` antes de tratar el dato, el
  cese existe solo en la tabla. Es el mismo límite que ya tenía cualquier
  bloqueo, pero acá pesa distinto: es la respuesta que se le dio por escrito a
  un titular que pidió que borraran sus datos.
- **La inmutabilidad de la evidencia depende también de los permisos del motor**:
  quien tiene `DELETE` sobre `privacidad_textos` puede reescribir un texto
  publicado en su lugar y hacer que un consentimiento acredite algo que la
  persona no leyó. Los `GRANT` que hay que aplicar están en el spec de
  pendientes.

### Comandos

```bash
php artisan privacidad:rat                        # el RAT en tabla
php artisan privacidad:rat --json                 # el RAT para adjuntar
php artisan privacidad:aplicar-retencion          # simulación
php artisan privacidad:aplicar-retencion --ejecutar
php artisan privacidad:cifrar-texto-libre         # simulación
php artisan privacidad:cifrar-texto-libre --ejecutar
```

`--ejecutar` toma un candado (`Cache::lock`) y falla con código distinto de cero
si ya hay otra corrida en curso. La simulación no lo toma: siempre se puede
mirar. El candado está en el comando y no solo en el `withoutOverlapping()` del
schedule porque el solape que se midió no fue entre dos corridas del cron, sino
entre un `docker compose exec` cuyo timeout venció —el proceso siguió vivo
dentro del contenedor— y la corrida que alguien lanzó encima: MariaDB abortó una
de las dos con `SQLSTATE 1020`, que en SQLite y con un solo proceso la suite no
puede ver. Requiere un store de caché con soporte de candados (`redis`,
`database`, `memcached`, `file`, `array`); con uno que no lo soporte, el comando
corre sin candado.
