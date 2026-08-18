# Lo que apareció al preparar el ecosistema para la Ley 21.719

Fecha: 2026-08-18

Este documento existe porque los informes de trabajo viven en `.superpowers/sdd/`,
que está fuera del control de versiones. Lo que sigue son los hallazgos **medidos**
—no supuestos— al implementar el titular de datos en los sistemas municipales, y
que sobreviven a la sesión que los encontró.

Cada uno dice qué se midió y dónde, para que se pueda comprobar en vez de creer.

---

## 1. La integración con el maestro estaba desconectada en la mitad del ecosistema

Medido leyendo `config/services.php` de cada repo:

| Sistema | Estado |
|---|---|
| `credenciales-graneros` | **`config/services.php` no existe** |
| `licencias-graneros` | Existe, **sin la clave `personas_api`** → url y token en `null` |
| `control-acceso-graneros` | Existe, **sin `personas_api`** |
| `seguridad-graneros`, `web-graneros` | No existía; se creó al adoptar |
| `feria-graneros`, `discapacidad-graneros` | Correcto |

Es el gotcha de configuración ausente en su forma más cara: **Laravel devuelve
`null` en silencio**, así que el sistema parece configurado y no lo está. En
`seguridad-graneros` el write-through habría quedado mudo para siempre sin que
nadie se enterara.

En `licencias-graneros` se comprobó en vivo: el resolvedor efectivo es el local y
`services.personas_api.url` es `NULL`. El camino API está dormido, así que hoy no
rompe nada — pero el día que alguien cambie el driver falla sin avisar.

**Relacionado:** `ApiPersonaResolver` está **duplicado como código muerto** en
licencias, control-acceso y credenciales (cero referencias externas en los tres,
medido), mientras el paquete compartido trae el suyo.

## 2. El origen por omisión falseaba la traza de quién consulta

`Muni\Shared\Persona\ApiPersonaResolver` mandaba `X-Sistema` con valor por
omisión **`'discapacidad'`**. Ese encabezado es lo que el maestro guarda en
`persona_lookups` como origen de cada consulta sobre un vecino, así que un
adoptante que olvidara configurarlo **no quedaba sin trazar: quedaba trazado como
otro sistema**.

Una bitácora que atribuye mal quién consultó es peor que una que dice «no se
sabe». Corregido a `'desconocido'` (commit `9ae725d`). El docblock afirmaba
«trazabilidad completa» sin la condición que la sostiene; ahora la dice.

## 3. El titular de datos es distinto en cada sistema

No es «`composer update` ×6». Medido recorriendo los esquemas reales:

| Sistema | Titular | Nota |
|---|---|---|
| discapacidad, feria, credenciales | `Persona` | ya existía |
| control-acceso | `Person` | trabajadores y visitas |
| **licencias** | **`User`** | el ciudadano tiene cuenta: `rut`, `birth_date`, `direccion`, 103 de 112 con RUT. Crear una `Persona` aparte habría **partido sus derechos ARCOP en dos registros** |
| **seguridad** | no existía | el reportante vivía suelto en `incidentes` |
| **web-graneros** | no existía | vecinos sueltos en `contactos` |

## 4. Casi nadie deja RUT, y pedirlo habría sido ir para atrás

- **`web-graneros`: 100 % de las filas sin documento**, y no por omisión —
  *ninguna* tabla tiene columna de documento, y el sitio público **no tiene un
  solo formulario que recoja datos**.
- **`seguridad-graneros`: 29 de 29 incidentes sin RUT** del reportante.

Exigir RUT en esos formularios habría sido recoger un dato que la finalidad no
necesita —minimización— y crear un padrón de identificadores que no existía, con
más daño ante una brecha. La identidad se acredita por **canal**: quien controla
el correo o el teléfono responde por él, igual que en un «olvidé mi contraseña».
Por eso el contrato del módulo es `VerificadorIdentidad` y no un chequeo de
cédula fijo.

Consecuencia de diseño, aplicada en el scaffold: documento **nulo con unicidad
parcial**, y **sin documento el sistema no afirma identidad** — dos personas del
mismo nombre son dos filas. Fusionarlas sería declararlas la misma sin que nadie
lo comprobara, y equivocarse significa atribuirle a una los datos de la otra.

## 5. La anonimización se rompe distinto en cada sistema

El pendiente 1 del spec —contrastar el esquema contra lo que anula
`anonimizar()`— se confirmó, y **el bloqueo nunca fue el mismo**:

| Sistema | Qué impide poner null |
|---|---|
| discapacidad, feria | `nro_documento` UNIQUE + NOT NULL (en disc alimenta una columna GENERATED STORED) |
| **licencias** | **`email`** UNIQUE + NOT NULL, más `name` y `password`. El `rut` sí es nullable |

En licencias el centinela de correo lleva **salida por colisión**: sin ella,
cualquiera podía registrar `anon-{id}@anonimizado.invalid` y **bloquear la
supresión ajena**.

Y una trampa del helper compartido: `RutHelper::normalize('ANON-13017')` devuelve
`'1301-7'` — **normalizar un centinela inventa un RUT de aspecto plausible**. En
discapacidad no contamina porque la columna normalizada la genera la base
(`anon34`), y la verificación de identidad está protegida porque `validate()`
corre antes y rechaza el dígito verificador. Comprobado ejecutando.

## 6. Los archivos en disco son donde se escapa el expediente

- **`licencias-graneros`: dos discos, no uno.** `documentos.file_path` vive en el
  disco `documentos`, pero `solicitudes.licencia_path` en el **de por defecto**.
  Purgar solo el primero dejaba vivo el PDF de la licencia, **con nombre y RUT
  impresos**.
- Un `Storage::delete()` que falla **no cuenta como purga**. Anular la ruta sin
  borrar el archivo deja el documento huérfano en disco: el error ya se cometió
  una vez en el propio módulo.

## 7. Lo que una FK no alcanza a cubrir

- **`seguridad-graneros`: el texto libre.** `relato`, `observacion` y `notas`
  nombran personas, no están cifrados ni vinculados a `Persona`, y **una
  supresión sobre `personas` no los toca**. Es el hueco real de la ley ahí.
- **`licencias-graneros`: `restricciones_legales`** se une por RUT **sin FK** y
  guarda si la persona es deudora de pensión alimenticia o prófuga. Anular el RUT
  dejaba esas filas huérfanas con el dato judicial y el RUT real. Y en
  `password_reset_tokens` la clave primaria **es** el correo.
- **`web-graneros`: `concejales`** — 6 personas naturales reales sin vincular, la
  mayor concentración de datos personales del sistema.
- **`web-graneros`: contenido editorial** (alcalde en páginas fijas, noticias):
  fuera del alcance de una FK.

## 8. La sesión abierta sobrevivía a la anonimización

En `licencias-graneros`, verificado **ejecutando**: tras anonimizar, el portal
seguía devolviendo 200. Con sesiones en Redis, borrar la tabla `sessions` no
alcanza. Se agregó un middleware, registrado en el grupo `web` **y** en la pila
del panel por separado, porque Filament no hereda el grupo `web`.

## 9. Propagar la supresión mejora, pero no certifica

`licencias-graneros` propaga altas y cambios desde siempre (96 de 112 usuarios
sellados), pero **no propagaba supresiones**: se anonimizaba localmente y la
identidad seguía viva y consultable por RUT desde los otros siete sistemas. Ya
está cerrado.

**El límite sigue en pie y es del ecosistema:** el `baja()` del maestro es un
*soft delete* y su `upsert()` revive al suprimido con `restore()`. Ningún sistema
puede certificar una supresión mientras eso siga así. Ver la sección BLOQUEANTE
del spec de pendientes.

Hallazgo nuevo del mismo tipo: `baja()` inserta en `persona_lookups` una fila con
`rut_buscado` = el RUT real, así que **el acto de suprimir deja rastro con el
dato personal de quien pidió que lo borraran**.

## 10. Nada de esto es instalable todavía

Los seis `composer.lock` declaran `v1.12.1` de `laravel-muni-shared`, con
`source.reference = 975a7d8`. Ese commit **no contiene el módulo**:

```
$ git ls-tree -r --name-only 975a7d8 | grep -c '^src/Privacidad'   → 0
$ git ls-tree -r --name-only HEAD     | grep -c '^src/Privacidad'  → 69
```

Y el remoto solo tiene tags hasta **v1.9.0**. Por eso las capacidades del
contrato se implementaron como **métodos concretos sin `implements`** en licencias
y feria: adoptarlo será agregar una línea cuando exista el tag.
