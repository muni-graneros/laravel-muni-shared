# Panel ARCOP en discapacidad — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un funcionario del mesón pueda registrar y resolver una solicitud ARCOP. Hoy el ciclo completo existe, está probado y es correcto — y es inalcanzable: no hay ninguna superficie por la que se use.

**Architecture:** Se construye en `discapacidad-graneros`, no en `laravel-muni-ui`. Razón: muni-ui no tiene tests ni Filament en `require-dev`, así que ponerlo ahí obliga a montar el arnés antes de escribir UI; y generalizar antes de probar contra la realidad es donde nacieron los defectos caros de este proyecto. Se extrae a muni-ui **después** de que funcione con datos reales.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Pest 4, MariaDB.

**Módulo:** `Muni\Shared\Privacidad` (321 tests). **Adopción:** `discapacidad-graneros` (350 tests).

## Alcance, y lo que queda fuera a propósito

**Dentro:** el ciclo de la solicitud ARCOP — registrar con identidad acreditada, ver el semáforo de plazo, tomar, resolver acogiendo o rechazando con fundamento, y entregar el expediente en acceso y portabilidad.

**Fuera de este plan** (administrativo, no de mesón): la administración del RAT, el registro de brechas, los encargados y los textos informativos. Son pantallas de configuración que hoy se resuelven por seeder y consola, y no bloquean la atención de un ciudadano.

## Global Constraints

- Repo: `discapacidad-graneros`, rama `feat/privacidad-21719`. **No pushear, no mergear, no etiquetar, no tocar ningún remoto.**
- Commits en español, sin atribución a IA.
- No modificar `vendor/`. Si aparece un defecto del módulo, se reporta, no se parchea ahí.
- `docs/*` está en el `.gitignore` de este repo por decisión propia: los reportes van al scratch de `laravel-muni-shared`.
- Tests en Pest, en español. `make test` es obligatorio (hay una guarda que impide correr contra la BD de desarrollo).
- Cada tarea termina con la suite completa en verde, Pint y PHPStan nivel 8 limpios.
- Convenciones del repo: leer `.claude/skills/**` y usar el MCP de Laravel Boost (`search-docs` antes de escribir, `database-schema` para leer el esquema real).

## La regla que este proyecto pagó doce veces

*Cada afirmación se verificó en la dimensión en que el autor pensaba, y se enunció una dimensión más ancha.* Antes de escribir un docblock que afirme una garantía, preguntar qué afirma la frase más allá de lo ejecutado — y o ejecutar eso también, o angostar la frase.

---

### Task 1: El recurso de solicitudes, en solo lectura

Primero ver, después escribir. Una lista con semáforo de plazo ya tiene valor por sí sola: hoy nadie puede saber qué solicitudes están por vencer.

**Files:**
- Create: `app/Filament/Discapacidad/Resources/SolicitudResource.php` (+ su página de listado)
- Create: `tests/Feature/Privacidad/Panel/SolicitudListadoTest.php`

**Interfaces:**
- Consumes: `Muni\Shared\Privacidad\Modelos\Solicitud` y sus scopes `pendientes()`, `porVencer()`, `vencidas()`; `TipoDeSolicitud`, `EstadoDeSolicitud`.
- Produces: un recurso de Filament en solo lectura, sin crear ni editar.

- [ ] **Step 1: Leer antes de escribir**

Leer el modelo `Solicitud` en `vendor/muni-graneros/laravel-muni-shared/src/Privacidad/Modelos/`, sus scopes y su método `diasRestantes()`. Leer un recurso existente del repo (`PersonaResource`) para seguir sus convenciones de tabla, filtros y permisos.

Consultar `search-docs` del MCP de Boost para la sintaxis de tablas y badges de la versión de Filament instalada. **No escribir de memoria**: la API de Filament cambia entre mayores.

- [ ] **Step 2: Write the failing test**

`tests/Feature/Privacidad/Panel/SolicitudListadoTest.php` debe cubrir:

- Un funcionario con permiso ve la lista.
- Una solicitud por vencer aparece marcada distinto de una vencida y de una resuelta.
- Las solicitudes de **otro sistema** (`sistema != 'discapacidad'`) no aparecen: la tabla es compartida por el ecosistema y filtrar por sistema no es cosmético, es aislamiento de datos entre municipios y sistemas.
- Un usuario sin el permiso recibe 403.

Usar los helpers de test de Filament de la versión instalada, no `get()` a la URL.

- [ ] **Step 3: Run test to verify it fails**

`make test` — debe fallar por recurso inexistente.

- [ ] **Step 4: Implementar el recurso**

Columnas: tipo, estado, fecha de recepción, vencimiento con semáforo, y el titular. **El titular es un morph**: mostrarlo sin asumir que siempre es `Persona`, y sin romper cuando la fila está huérfana (anonimizada) — ese caso existe y debe verse como tal, no como un error.

Filtros por estado y por tipo. Orden por defecto: las que vencen antes, primero.

Registrar el permiso en el seeder de Shield del repo, siguiendo el patrón existente.

- [ ] **Step 5: Verificar y commitear**

`make test`, Pint, PHPStan. Commit en español.

---

### Task 2: Registrar una solicitud con identidad acreditada

**Files:**
- Modify: `app/Filament/Discapacidad/Resources/SolicitudResource.php` (+ página de creación)
- Create: `tests/Feature/Privacidad/Panel/SolicitudRegistroTest.php`

**Interfaces:**
- Consumes: `Muni\Shared\Privacidad\Solicitudes::registrar()`, `App\Privacidad\VerificacionPresencialCedula`, `ResultadoVerificacion`, `Solicitante`.

> **El panel NO escribe la fila.** Llama al servicio del módulo. `Solicitud::create()` desde un `CreateRecord` saltaría la verificación de identidad, el cálculo del plazo, el régimen de edad y la evidencia — es el hueco 6 del spec de pendientes, escrito precisamente para esto.

- [ ] **Step 1: Write the failing test**

- Registrar una solicitud acreditando la cédula del titular la crea, con `vence_en` calculado y su entrada en bitácora.
- Un RUT que no corresponde al titular **no crea nada** y muestra el error al funcionario.
- Un titular sin fecha de nacimiento acreditada es rechazado con el mensaje del módulo, no con un error genérico.
- Un menor de edad exige representante acreditado.

- [ ] **Step 2: Verificar que falla**

- [ ] **Step 3: Implementar**

El formulario pide: titular (buscador por RUT, reusando el patrón de autocompletado que ya existe en el repo), tipo de solicitud, detalle, el RUN leído de la cédula, y quién ejerce el derecho.

La acción de guardar construye el `ResultadoVerificacion` con el verificador enchufado y llama a `Solicitudes::registrar()`. Las excepciones de dominio del módulo (`IdentidadNoVerificada`, `EdadNoAcreditada`, y las del régimen de NNA) se traducen a notificaciones legibles: **el mensaje del módulo ya dice qué hacer**, no reemplazarlo por uno genérico.

- [ ] **Step 4: Verificar y commitear**

---

### Task 3: Resolver la solicitud

**Files:**
- Modify: `SolicitudResource` (acciones)
- Create: `tests/Feature/Privacidad/Panel/SolicitudResolucionTest.php`

**Interfaces:**
- Consumes: `Solicitudes::tomar()`, `acoger()`, `acogerParcialmente()`, `rechazar()`; `Rectificaciones::aplicar()`; `ExportacionDeDatos::paraSolicitud()`.

- [ ] **Step 1: Write the failing test**

- Acoger exige fundamento; sin él, el módulo lanza y el panel lo muestra.
- Una solicitud ya resuelta no se puede resolver de nuevo.
- Resolver **levanta el bloqueo** que la solicitud había puesto (rectificación y oposición).
- Una rectificación se aplica por `Rectificaciones::aplicar()`, respetando `camposRectificables()`: un campo fuera de la lista se rechaza.
- Acceso y portabilidad entregan el expediente por `ExportacionDeDatos::paraSolicitud()` y dejan su evidencia.

- [ ] **Step 2: Verificar que falla**

- [ ] **Step 3: Implementar**

Acciones separadas por tipo: rectificación pide los campos a corregir (limitados a `camposRectificables()` del titular); acceso y portabilidad ofrecen descargar el expediente; supresión y oposición piden solo el fundamento.

**Separación de funciones:** quien registró la solicitud no debería resolverla. El módulo lo permite pero el panel debe al menos advertirlo, y los permisos de Shield deben poder separarse. Implementar la separación de permisos; la prohibición dura es decisión del municipio y va documentada, no impuesta.

- [ ] **Step 4: Verificar y commitear**

---

### Task 4: El widget de plazos y la verificación en vivo

**Files:**
- Create: un widget de dashboard con las solicitudes por vencer y vencidas
- Create: `docs/privacidad/verificacion-panel-discapacidad.md` **en `laravel-muni-shared`** (este repo ignora `docs/*`)

- [ ] **Step 1: El widget**

Cuenta de solicitudes por vencer y vencidas, enlazando al listado filtrado. Con su test.

- [ ] **Step 2: Verificación en vivo**

Levantar el sistema y **usar el panel como lo usaría un funcionario**: registrar una solicitud de acceso acreditando una cédula, verla en el listado, resolverla, descargar el expediente, y confirmar en la base que quedó la evidencia.

**Escribir lo que falle.** En este proyecto, cada vez que algo se ejecutó contra la realidad en vez de razonarse, apareció algo caro: cinco fugas de datos, una anonimización muerta en MariaDB, un `REPLACE INTO` que sorteaba un trigger, un disco mal configurado, y una suite entera donde la retención era un no-op silencioso. Un informe que diga «todo funcionó» es el resultado menos probable.

- [ ] **Step 3: Commitear la evidencia**

---

## Después de este plan

Extraer el recurso a `laravel-muni-ui` como plugin reusable — con su arnés de pruebas, que hoy no existe ahí— para que licencias, feria y control-acceso lo hereden sin reimplementarlo. Eso es un plan aparte y **no se empieza hasta que este panel haya atendido una solicitud de verdad**.
