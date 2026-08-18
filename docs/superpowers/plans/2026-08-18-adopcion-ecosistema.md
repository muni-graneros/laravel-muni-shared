# Adoptar el módulo Privacidad en el resto del ecosistema — plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Un sistema por vez, en el orden de abajo. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Que los sistemas municipales que tratan datos de vecinos cumplan la Ley 21.719 antes del 1 de diciembre de 2026.

**Estado de partida, medido el 2026-08-18** (no supuesto):

Seis sistemas ya requieren `muni-graneros/laravel-muni-shared` en su `composer.json`, así que la instalación del módulo es, en teoría, `composer update` + `migrate`. **En la práctica ninguno lo tiene**, y conviene saber por qué antes de intentarlo.

## El bloqueo, primero

Los seis `composer.lock` declaran `v1.12.1` instalada, con `source.reference = 975a7d8`. Ese commit **no contiene el módulo**:

```
$ git ls-tree -r --name-only 975a7d8 | grep -c '^src/Privacidad'   → 0
$ git ls-tree -r --name-only HEAD     | grep -c '^src/Privacidad'  → 69
```

Y el remoto solo tiene tags hasta **v1.9.0**: `v1.10`–`v1.12.1` no existen como etiqueta publicada.

**Consecuencia:** ningún sistema puede instalar el módulo hasta que exista una versión etiquetada que lo contenga. Es un `git tag` + push, decisión de César (punto 9 de `docs/privacidad/decisiones-del-municipio.md`), y no se toma desde acá.

Hasta entonces, la única alternativa es replicar el andamio de `discapacidad-graneros` —repositorio `path` + symlink en `vendor/` + montaje en el compose— en cada repo. **No se recomienda**: son siete andamios frágiles, y ese montaje ya se cobró un defecto silencioso en disc (los contenedores `worker` y `reverb` sin el volumen, con la cola muerta y Reverb caído sin aviso).

## Lo que NO es este trabajo: «composer update ×6»

El inventario de abajo es la razón. **El titular de datos es distinto en cada sistema, y en tres no hay un modelo que lo represente.** Eso es lo primero que hay que resolver en cada uno, y no lo puede deducir el módulo.

| Sistema | Titular candidato | Write-through al maestro | Salidas medidas (Http · correo · export · api) |
|---|---|---|---|
| `feria-graneros` | `Persona` ✔ | **Sí** | 5 · 6 · 1 · 2 |
| `credenciales-graneros` | `Persona` ✔ | No | 2 · 2 · 0 · 0 |
| `control-acceso-graneros` | `Person` (+ `DeviceUser`) | No | 7 · 6 · 1 · 6 |
| `licencias-graneros` | **ninguno** — el postulante vive en `Solicitud`/`CitaVisita`, identificado por RUT contra el maestro | **Sí** | 13 · 15 · 2 · 2 |
| `seguridad-graneros` | **ninguno** — personas dentro de `Incidente`, `Infraccion`, `Derivacion`, `TurnoOcupante` | No | 3 · 4 · 1 · 4 |
| `web-graneros` | **ninguno** — `Contacto`, `Prestamo`, `OfertaLaboral`, `Organizacion` | No | 6 · 9 · 8 · 0 |

Dos lecturas que cambian el plan:

1. **Los tres «ninguno» son los más caros, no los más baratos.** `TitularDeDatos` se implementa sobre un modelo; sin un modelo que agrupe a la persona, hay que decidir primero si se crea uno, si el contrato se implementa sobre cada modelo que la contiene, o si ese sistema se declara sin titulares propios. Es una decisión de arquitectura por sistema.
2. **`seguridad-graneros` y `control-acceso-graneros` tratan datos sensibles** —incidentes, infracciones, control biométrico de acceso—, así que su causal del art. 16 pesa más que en el resto. No son los últimos de la fila por ser «internos».

## Orden propuesto, y por qué

1. **`feria-graneros`** — el más parecido a disc: tiene `Persona` y write-through. Se puede reusar casi tal cual `PropagaRectificacionAlMaestro`, `PropagaSupresionAlMaestro` y el patrón del candado. Sirve para confirmar que la adopción es replicable antes de gastar en los difíciles.
2. **`credenciales-graneros`** — tiene `Persona` y no propaga: enlaza `SupresionSoloLocal` y es el más corto.
3. **`control-acceso-graneros`** — `Person` existe, pero datos sensibles y seis APIs que revisar.
4. **`licencias-graneros`** — el de más salidas (13 Http, 15 notificaciones) y sin titular local.
5. **`seguridad-graneros`** y 6. **`web-graneros`** — deciden primero su modelo de titular.

## Lo que cada sistema necesita, y quién lo puede hacer

Lo que se puede transcribir del trabajo de disc:

- [ ] Instalar y migrar (bloqueado por el tag).
- [ ] `TitularDeDatos` sobre el modelo elegido. **Ojo con `anonimizar()`**: en disc, `nro_documento` es NOT NULL + UNIQUE y alimenta una columna GENERATED STORED, así que escribe `ANON-{id}`. Contrastar el esquema real de cada sistema antes de escribirlo — es el pendiente 1 del spec.
- [ ] `ResuelveTitularesVencidos` (la «última señal de vida» propia del dominio).
- [ ] `VerificadorIdentidad` — la cédula en el mesón sirve donde hay mesón.
- [ ] Enlazar `PropagaRectificacion`/`PropagaSupresion`, o `SupresionSoloLocal` **declarado**. Sin declaración, `AplicarRetencion` se niega a correr, que es lo correcto.
- [ ] El panel ARCOP (mejor: heredarlo del plugin de `muni-ui`, ver `2026-08-17-extraer-panel-arcop-a-muni-ui.md`).
- [ ] Las variables del `.env`, **incluidas `PRIVACIDAD_CONTACTO` y `PRIVACIDAD_DELEGADO`**: sin ellas el expediente que se le entrega al vecino sale con el contacto en blanco. Medido en disc.

Lo que **no** se puede transcribir, y define el calendario real:

- [ ] **El RAT del sistema.** Qué trata, con qué base, con qué norma y por cuánto tiempo. Es una declaración jurídica; el seeder solo la escribe.
- [ ] **El mapeo tratamiento → finalidad y su candado.** El módulo no sabe qué CSV, qué job ni qué pantalla de ese repo pertenece a qué finalidad. Sin candado, el panel le promete a un vecino un cese que no ocurre — que es exactamente el estado en que estuvo disc hasta que se construyó `App\Privacidad\CeseDeTratamiento`.

En disc, ese recorrido encontró **una salida de datos que no estaba en ningún mapeo** (el resumen de atenciones al LLM) y **tres que el RAT no nombraba**. Los números de la tabla de arriba dicen que en licencias hay bastante más superficie que revisar que en disc.

## Antes de empezar cualquiera de los seis

Leer, en este orden:

1. `docs/privacidad/decisiones-del-municipio.md` — las doce decisiones que no cierra el código.
2. `docs/privacidad/verificacion-adopcion-discapacidad.md` y `verificacion-panel-discapacidad.md` — lo que apareció al ejecutar contra la realidad, que es la mejor guía de dónde mirar.
3. `docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md` — los residuos abiertos, incluida la sección BLOQUEANTE del maestro, que afecta a **todos** los adoptantes por igual: mientras `personas-graneros` haga soft delete y su `upsert()` reviva al suprimido, ningún sistema puede certificar una supresión.
