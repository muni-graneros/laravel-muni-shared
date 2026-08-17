# Extraer el panel ARCOP a laravel-muni-ui — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que los otros siete sistemas del ecosistema hereden el panel ARCOP en vez de reimplementarlo siete veces.

**Repo:** `~/Dev/laravel-muni-ui` (paquete `muni-graneros/laravel-muni-ui`).

**Depende de:** que el panel de `discapacidad-graneros` haya atendido una solicitud de verdad, con la verificación en vivo escrita. **No empezar antes.** Generalizar antes de probar contra la realidad es de donde salieron los defectos caros de este proyecto.

## Estado real del repo destino (verificado, no supuesto)

Esto es lo primero que hay que saber, porque cambia el tamaño del trabajo:

- `composer.json` declara `autoload-dev` apuntando a `tests/`, **y ese directorio no existe**. No hay Pest, no hay PHPUnit, no hay un solo test.
- **Filament está solo como `suggest`**, no en `require` ni en `require-dev`. Hoy el paquete no puede ni instanciar un recurso de Filament para probarlo.
- `src/Filament/` tiene un solo archivo: `MuniPanel.php`.

O sea: **antes de mover una línea de código hay que montar el arnés**. Ese es el trabajo real de este plan; mover el recurso es la parte fácil.

## Por qué no se hizo así desde el principio

Se decidió construir el panel en `discapacidad-graneros` justamente porque acá no había con qué probarlo, y montar el arnés primero habría retrasado el panel sin que nadie supiera todavía si el diseño servía. Ahora que sirve, se paga.

## Global Constraints

- **No pushear, no mergear, no etiquetar, no crear PRs, no tocar ningún remoto.**
- Commits en español, sin atribución a IA.
- El paquete es consumido por 4+ sistemas ya en producción. **Nada de este plan puede romper `MuniPanel`.**
- Agregar Filament como `require-dev`, nunca como `require`: un sistema que use los componentes Blade sin panel no debe arrastrar Filament entero.

---

### Task 1: El arnés de pruebas

**Files:** `composer.json`, `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php`, un primer test de humo.

- [ ] **Step 1: Montar Testbench**

`orchestra/testbench` ya está en `require-dev`. Falta el `TestCase` que registra `MuniUiServiceProvider`, la config de PHPUnit y Pest.

- [ ] **Step 2: Un test de humo que pruebe lo que YA existe**

Antes de traer nada nuevo: un test sobre `MuniPanel`, que hoy no tiene ninguno. Si el arnés no puede probar lo que ya está en producción, no va a poder probar lo que viene.

- [ ] **Step 3: Filament en require-dev, y CI**

Y un workflow que corra la suite. Un paquete compartido sin CI es uno donde el próximo cambio rompe cuatro sistemas en silencio.

- [ ] **Step 4: Commit**

---

### Task 2: Traer el recurso, sin generalizarlo todavía

**Files:** `src/Filament/Privacidad/` (recurso + páginas), `src/PrivacidadPanelPlugin.php`.

- [ ] **Step 1: Copiar tal cual y hacerlo pasar**

Traer el `SolicitudResource` de `discapacidad-graneros` **sin rediseñarlo**, con sus tests, y hacerlos correr acá. Cualquier cosa que no compile por depender de `App\` del adoptante es exactamente lo que la tarea 3 tiene que resolver — anotarla, no arreglarla todavía.

- [ ] **Step 2: Commit**

---

### Task 3: Cortar las dependencias del adoptante

Acá está la sustancia. El recurso de disc depende de cosas que el paquete no tiene y **no debe adivinar**:

- **Quién es el titular.** Disc usa `App\Models\Persona`. El paquete solo conoce el contrato `TitularDeDatos`. El recurso tiene que trabajar contra el contrato y contra el morph, nunca contra un modelo concreto.
- **Cómo se acredita la identidad.** Disc usa `VerificacionPresencialCedula` (cédula en el mesón). Otro sistema puede acreditar de otra forma. Va por el contrato `VerificadorIdentidad`.
- **El buscador de titulares.** Disc busca por RUT con su propio autocompletado. El paquete necesita un punto de extensión, no una copia.
- **Los permisos de Shield.** Los nombres de permiso son del adoptante.

- [ ] **Step 1: Un test por dependencia cortada**

Que el recurso funcione con un titular de prueba **que no sea `Persona`** — un modelo doble definido en los tests del paquete. Ese test es el que prueba de verdad que se generalizó: si solo se prueba con algo parecido a `Persona`, no se cortó nada.

- [ ] **Step 2: Implementar y commitear**

---

### Task 4: Devolverle el recurso a discapacidad

- [ ] **Step 1: Que disc consuma el plugin**

Borrar el recurso local de `discapacidad-graneros` y registrar el plugin del paquete. **Los 403 tests de disc son el contrato de no-regresión**: si alguno se pone rojo, la extracción perdió algo. Ese es el punto entero de hacerlo en este orden.

- [ ] **Step 2: Correr la suite de disc completa y mostrar la salida**

- [ ] **Step 3: Verificar en vivo otra vez**

Sí, de nuevo. La suite verde ya demostró en este proyecto que no basta: hubo una anonimización muerta en producción con 27 tests en verde. Entrar al panel y resolver una solicitud.

- [ ] **Step 4: Commit en los dos repos**

---

## Lo que este plan NO resuelve

El paquete **sigue sin poder obligar** a un adoptante a honrar un bloqueo. `CeseDeTratamiento` —el candado que hace cesar el tratamiento de verdad— se construyó en `discapacidad-graneros` porque el mapeo tratamiento→finalidad es propio de cada sistema: el paquete no sabe qué pantalla, qué CSV ni qué job de otro repo pertenece a qué finalidad.

Extraer el panel hace que los otros siete hereden **la superficie** para recibir y resolver solicitudes. No hace que cumplan: cada uno tiene que escribir su propio candado, y **si no lo escribe, su panel va a prometer un cese que no ocurre** — exactamente lo que pasaba en disc hasta hoy.

Eso tiene que quedar en el README del paquete y en el checklist de adopción, no enterrado en un plan.
