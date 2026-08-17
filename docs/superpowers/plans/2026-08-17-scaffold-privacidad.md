# El módulo Privacidad en el scaffold — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que todo proyecto nuevo generado con el scaffold nazca cumpliendo la Ley 21.719 — no con la dependencia instalada, sino con la implementación puesta.

**Repo:** `~/Dev/scaffold-laravel-filament-pwa`. Genera proyectos con `./scaffold new "Nombre"`.

**Depende de:** el panel ARCOP funcionando en `discapacidad-graneros`. **No empezar antes.**

## El peligro que este plan existe para evitar

Si el scaffold solo agrega la dependencia y corre las migraciones, cada proyecto nuevo nace con seis tablas `privacidad_*`, un `privacidad:rat` que exporta un RAT vacío y una retención enlazada a `NingunTitularVencido` que no purga nada jamás.

**Eso parece cumplimiento y no es nada.** Y es exactamente el patrón que este proyecto lleva trece hallazgos persiguiendo: algo que afirma una garantía que no sostiene. Multiplicarlo por cada sistema municipal futuro sería el peor resultado posible de todo este trabajo.

Así que el scaffold **genera la implementación**, no la dependencia.

## Estado del scaffold hoy (verificado)

- Modelos: `User` y los tres de onboarding. **No hay `Persona`.**
- Requiere `muni-graneros/laravel-muni-ui ^0.11`. **No requiere `laravel-muni-shared`.**
- 14 archivos de test.
- `./scaffold` es un generador bash que copia el repo y renombra.

## La decisión que el scaffold no puede tomar solo

**¿Quién es el titular de datos en un proyecto genérico?**

Los sistemas municipales tienen `Persona` — el ciudadano. El scaffold solo tiene `User` — el funcionario del panel. Son titulares distintos con tratamiento legal distinto: la ficha de un vecino es dato personal que él puede pedir, rectificar y suprimir; la cuenta de un funcionario también es dato personal, pero el régimen y las finalidades no son los mismos.

Tres salidas posibles, y **la elección es de César**:

1. **Preguntar al generar.** `./scaffold new` pregunta si el sistema atiende ciudadanos. Si sí, genera `Persona` + `TitularDeDatos` sobre ella; si no, sobre `User`. Más trabajo en el generador, resultado correcto en ambos casos.
2. **Generar siempre sobre `User`**, documentando que un sistema con ciudadanos debe mover la implementación a su modelo de persona. Simple, y deja al adoptante una tarea que puede olvidar.
3. **No generar la implementación**, solo el andamiaje y un test que falla hasta que alguien la escriba. Honesto, imposible de ignorar, y molesto.

**Recomendación: la 1**, con la 3 como red — si el generador no sabe, deja el test que falla en vez de adivinar.

## Global Constraints

- Repo: `scaffold-laravel-filament-pwa`. **No pushear, no mergear, no etiquetar, no tocar ningún remoto.**
- Commits en español, sin atribución a IA.
- Los proyectos generados deben quedar con la suite en verde y Pint limpio. Un scaffold que genera un proyecto roto es peor que uno que no genera nada.
- Verificar generando un proyecto de prueba y corriéndolo — no razonando sobre el generador.

---

### Task 1: La dependencia y la configuración

**Files:**
- Modify: `composer.json` (agregar `muni-graneros/laravel-muni-shared` y su repositorio `vcs`)
- Modify: `.env.example`
- Modify: `README.md`

- [ ] **Step 1: Agregar el paquete**

`laravel-muni-shared` con la restricción de la versión publicada. **Si el paquete todavía no está publicado, este plan no puede completarse**: pararse acá y decirlo, en vez de dejar un repositorio `path` en un generador que se copia a proyectos nuevos.

- [ ] **Step 2: Las variables obligatorias en `.env.example`**

`PRIVACIDAD_SISTEMA` (sin default en el paquete: sin él el RAT sale vacío), `PRIVACIDAD_DISCO_EVIDENCIA` (sin él la anonimización no borra los documentos y falla fuerte, por diseño), `PRIVACIDAD_PLAZO_RESPUESTA_DIAS`, `PRIVACIDAD_PLAZO_NOTIFICACION_BRECHA_DIAS`, `PRIVACIDAD_RESPONSABLE`, `PRIVACIDAD_CONTACTO`, `PRIVACIDAD_DELEGADO`, `PRIVACIDAD_RETENCION_HORA`.

Cada una con un comentario que diga qué pasa si falta. Leer `laravel-muni-shared/README.md` para los valores y las consecuencias reales; no inventarlas.

- [ ] **Step 3: Verificar generando**

`./scaffold new "Prueba Privacidad"` en un directorio temporal, `composer install`, `migrate`. Las tablas `privacidad_*` deben existir. Borrar el proyecto de prueba después.

- [ ] **Step 4: Commit**

---

### Task 2: La implementación del titular

**Files:** dependen de la decisión de arriba. Si es la opción 1, toca el generador `scaffold`.

- [ ] **Step 1: Implementar la decisión tomada**

Los siete métodos de `TitularDeDatos` sobre el modelo elegido. **`anonimizar()` es donde se rompe todo**: leer el hallazgo de `discapacidad-graneros` —`nro_documento` NOT NULL + UNIQUE alimentando una columna generada— y generar un `anonimizar()` que no asuma que las columnas admiten null.

Para `User`, el equivalente: `email` suele ser UNIQUE y NOT NULL.

- [ ] **Step 2: El seeder de finalidades con un test que falla**

El RAT de un sistema nuevo **no se puede generar**: declarar qué trata un sistema, con qué base legal y por cuánto tiempo es una afirmación jurídica. El scaffold genera el archivo con las finalidades en blanco **y un test que falla hasta que alguien las complete**.

Ese test es el entregable más valioso de este plan: obliga a hacerse la pregunta al crear el proyecto, que es el único momento en que alguien la va a contestar.

- [ ] **Step 3: Los otros contratos**

`ResuelveTitularesVencidos`, `VerificadorIdentidad` y —si el sistema es modelo de lectura de un maestro— `PropagaRectificacion` y `PropagaSupresion`. Generar implementaciones mínimas y honestas, o el test que falla. **Nunca una que devuelva un valor que parezca correcto y no lo sea** — un `vencidos()` que devuelve siempre vacío es un sistema que jamás purga y no lo dice.

- [ ] **Step 4: Verificar generando y commitear**

---

### Task 3: El panel

- [ ] **Step 1: Esperar la extracción**

El recurso ARCOP se está construyendo en `discapacidad-graneros` y se extraerá a `laravel-muni-ui` como plugin. **Este paso depende de esa extracción** y no se hace antes: generar una copia del recurso en el scaffold crearía una segunda implementación que divergirá de la primera.

- [ ] **Step 2: Registrar el plugin en el PanelProvider generado**

Junto a `MuniPanel`, que ya está.

- [ ] **Step 3: Verificar generando y commitear**

---

### Task 4: Verificación end-to-end

- [ ] **Step 1: Generar un proyecto limpio y usarlo**

`./scaffold new`, levantarlo, entrar al panel, registrar una solicitud ARCOP de prueba, resolverla. Correr `privacidad:rat` y `privacidad:aplicar-retencion` en simulación.

- [ ] **Step 2: Escribir qué falló**

En `laravel-muni-shared/docs/privacidad/verificacion-scaffold.md`. En este proyecto, cada ejecución contra la realidad encontró algo: cinco fugas de datos, una anonimización muerta en MariaDB, un `REPLACE INTO` que sorteaba un trigger, un disco mal configurado, una suite entera donde la retención era un no-op. **Un informe que diga «todo funcionó» es el resultado menos probable.**

- [ ] **Step 3: Commit**
