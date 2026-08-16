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
PRIVACIDAD_PLAZO_NOTIFICACION_BRECHA_DIAS=3
PRIVACIDAD_DISCO_EVIDENCIA=local
PRIVACIDAD_RESPONSABLE="I. Municipalidad de Graneros"
PRIVACIDAD_CONTACTO=privacidad@municipalidadgraneros.cl
PRIVACIDAD_DELEGADO=
```

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
| `ResuelveTitularesVencidos` | Solo si hay retención | Desde cuándo se trata a un titular bajo cada finalidad |
| `VerificadorIdentidad` | Por convención | Cómo se acredita que el solicitante es el titular. El paquete **no lo resuelve del contenedor**: `Solicitudes::registrar()` recibe un `ResultadoVerificacion` ya construido. Es el código que llama —la acción del panel, el mesón— el que debe verificar con él antes de registrar; implementarlo y no usarlo no protege nada |
| `PropagaRectificacion` | Solo si es modelo de lectura del maestro | Que la rectificación no la pise la próxima sincronización. **Debe ser síncrono**: tiene que conocer la respuesta del maestro antes de devolver. Despachar un job en cola y devolver `true` no es una implementación válida —informa éxito antes de que el maestro haya visto nada—. Devolver `false` o lanzar significan lo mismo: no se propagó |
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

### Límites declarados (leer antes de adoptar)

Lo que el módulo **no** hace, dicho acá y no en un scratch que se borra. El
detalle y el resto de los huecos abiertos están en
`docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md`.

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
```
