# Ley 21.719 — lo que el municipio tiene que decidir

Fecha: 2026-08-17

Este documento existe porque hay una clase de pendiente que **ninguna cantidad de
código cierra**. El módulo `Muni\Shared\Privacidad` implementa la mecánica de la
ley; las decisiones de abajo son declaraciones jurídicas y de política municipal
que solo puede tomar quien responde por el tratamiento.

Están escritas para que alguien que no siguió este desarrollo pueda contestarlas.
Cada una dice **qué se decide**, **qué está puesto hoy**, **qué pasa si nadie
decide**, y **quién debería contestar**.

Están ordenadas por lo que cuesta dejarlas sin contestar, no por dificultad.

---

## 1. Los plazos de retención y las normas habilitantes

**Qué se decide:** por cuántos meses conserva cada sistema cada categoría de
dato, y con qué norma exacta se sostiene tratarla sin consentimiento.

**Qué está puesto hoy:** en `discapacidad-graneros`, el seeder
`FinalidadesPrivacidadSeeder` declara seis finalidades con plazos y normas
**marcados en el propio archivo como propuestas pendientes de confirmación**. Se
eligieron para que el sistema funcione, no porque alguien con competencia
jurídica los haya fijado.

**Qué pasa si nadie decide:** el sistema purga datos según plazos que nadie
aprobó. Purgar antes de tiempo destruye información que el municipio está
obligado a conservar; purgar después la conserva ilegalmente. Las dos fallan, en
direcciones opuestas, y ninguna avisa.

Ya hubo un incidente de esta familia: el criterio de retención más corto
destruía lo que el más largo obligaba a conservar — 11.517 casos. Se corrigió en
el código (ahora solo se suprime a quien venció en **todas** las finalidades con
plazo), pero el código no puede saber cuál es el plazo correcto.

**Quién contesta:** dirección jurídica del municipio, por sistema.

---

## 2. La causal de tratamiento de datos sensibles

**Qué se decide:** con qué excepción del art. 16 se tratan los datos de salud y
discapacidad.

**Qué está puesto hoy:** una causal propuesta en el seeder, igual que el punto 1.

**Qué pasa si nadie decide:** el sistema de discapacidad trata datos sensibles —
la categoría más protegida de la ley— apoyado en una causal que eligió un
desarrollador. El RAT la va a mostrar tal cual ante una fiscalización.

**Quién contesta:** dirección jurídica.

---

## 3. El contrato con el Maestro de Personas

**Qué se decide:** si existe un contrato de encargo de tratamiento entre el
municipio y quien opera el maestro federado de personas, y hasta cuándo rige.

**Qué está puesto hoy:** el maestro está declarado como **Encargado** con
`contrato_*` en null, **a propósito**, para que `privacidad:rat` emita la
advertencia «Encargados sin contrato al día» en cada ejecución en vez de callar.

**Qué pasa si nadie decide:** la advertencia sigue saliendo. Es el
comportamiento correcto: la ley exige contrato con cada encargado, y un RAT que
no lo diga sería peor que uno que molesta.

**Quién contesta:** administración municipal. Si el contrato existe, se cargan
sus datos y la advertencia desaparece sola.

---

## 4. Qué pasa con el historial de una persona anonimizada

**Qué se decide:** si el `activity_log` histórico de un titular anonimizado se
borra, se anonimiza, o se conserva como cadena de auditoría.

**Qué está puesto hoy:** nada. El módulo desvincula la bitácora de privacidad
(`Bitacora::desvincular()`, que deja la fila huérfana sin perder la traza), pero
el `activity_log` de cada sistema no está tratado.

**Qué pasa si nadie decide:** queda un registro que, al lado de una ficha
anonimizada, puede reconstruir lo que la anonimización pretendía borrar.

**Quién contesta:** jurídica, con criterio de auditoría interna. Es una tensión
real entre dos obligaciones —trazabilidad y supresión— y no tiene respuesta
técnica única.

---

## 5. Cuánta granularidad estadística se sacrifica

**Qué se decide:** hasta dónde se degradan las fechas y los identificadores de
las filas para que una persona anonimizada no sea re-identificable.

**Qué está puesto hoy:** cuatro rondas de correcciones cerraron cuatro canales
de correlación (`titular_id`, `solicitud_id`, el ULID —cuyos primeros diez
caracteres son una marca de tiempo—, y el instante de anonimización).

**Y el residuo sigue abierto, medido:** un revisor escribió un atacante real —40
vecinos, 12 anonimizados, 72 horas de ruido— y reconstruyó **12 de 12** usando
únicamente fechas de negocio (`personas.created_at`) y el orden de los ids. Ese
canal es **estructural**: cerrarlo exige destruir información que los sistemas
usan para operar y reportar.

**Qué pasa si nadie decide:** la anonimización protege de una mirada casual, no
de alguien que se siente a correlacionar. Eso hoy está **documentado como
residuo, no declarado cerrado** — que es la única postura honesta disponible.

**Quién contesta:** es material de la Evaluación de Impacto (EIPD). Requiere
decidir cuánto reporte estadístico se está dispuesto a perder.

---

## 6. El bloqueo automático durante una rectificación

**Qué se decide:** si es aceptable que, mientras se resuelve una rectificación,
el dato quede bloqueado y el vecino no pueda ser atendido con él en el mesón.

**Qué está puesto hoy:** el bloqueo es automático, porque es lo que la ley
sugiere.

**Qué pasa si nadie decide:** puede aparecer como un problema de atención de
público — alguien pide corregir su dirección y queda temporalmente sin trámite.

**Quién contesta:** jefatura de atención de público. Si el bloqueo estorba, hay
alternativas (bloquear solo para ciertas finalidades), pero son trabajo de
desarrollo y hay que pedirlo.

---

## 7. Separación de funciones al resolver solicitudes

**Qué se decide:** si quien registra una solicitud ARCOP puede además
resolverla.

**Qué está puesto hoy:** los permisos **están separados** (`resolver_solicitud_arcop`
es distinto de `create_solicitud`), y el panel **advierte** cuando la misma
persona hace las dos cosas — pero no lo prohíbe.

**Qué pasa si nadie decide:** en un municipio chico, la misma funcionaria hará
las dos cosas, que puede ser perfectamente razonable. La advertencia queda.

**Quién contesta:** administración. Si se quiere prohibición dura, se activa
quitando el permiso; no hace falta desarrollo.

---

## 8. El maestro oculta al suprimido en vez de suprimirlo

**Qué se decide:** si `personas-graneros` cambia su `baja()`.

**Qué está puesto hoy:** `baja()` hace un *soft delete*. La identidad completa
—RUT, nombres, fecha de nacimiento, teléfono, correo, dirección— **sigue en la
tabla**; solo cambia `deleted_at`. Y cualquier otro sistema del ecosistema la
revive sin querer: `upsert()` busca con `withTrashed()` y hace `restore()`.

**Qué pasa si nadie decide:** la supresión del ecosistema dura hasta la
siguiente atención del mismo RUT en otra ventanilla. **Mientras esto no se
cierre, el municipio no puede afirmar ante la Agencia que ejerció el derecho de
supresión.** Es el pendiente bloqueante del módulo.

**Quién contesta:** es trabajo de desarrollo en otro repositorio
(`plataforma-graneros/personas-graneros`), no una decisión de política. Está
escrito en detalle en `docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md`.

---

## 9. Publicar el paquete

**Qué se decide:** publicar `laravel-muni-shared` para que los ocho sistemas del
ecosistema puedan instalarlo.

**Qué está puesto hoy:** el módulo y su adopción en `discapacidad-graneros`
viven **en un solo disco**, en ramas locales sin subir a ningún remoto. Fue una
regla deliberada durante todo el desarrollo: ningún agente tocó ningún remoto.

**Qué pasa si nadie decide:** nada de esto llega a ningún sistema. Y el scaffold
—para que cada proyecto nuevo nazca cumpliendo— **está bloqueado por esto**: un
generador que se copia a proyectos nuevos no puede apuntar a una carpeta del
disco de una máquina.

**Quién contesta:** César. Es la decisión que desbloquea más cosas de esta lista.

---

## 10. Una finalidad sin plazo declarado, ¿obliga a conservar?

**Qué se decide:** qué significa que una finalidad por función legal tenga
`plazo_retencion_meses` en null.

**Por qué aparece ahora:** desde que existe la supresión a petición del titular
(`Supresiones::aplicar()`), esa ambigüedad tiene consecuencias opuestas según
cómo se lea. Si null significa «conserva indefinidamente», el municipio le puede
negar la supresión a un vecino apoyándose en esa finalidad. Si significa «no hay
plazo declarado», no.

**Qué está puesto hoy:** null **no impide** la supresión. Es la única lectura
coherente con lo que ya hacía el sistema: `AplicarRetencion` lee ese mismo null
como «no conserva a nadie». La lectura contraria dejaría al módulo negándole la
supresión a una persona por una finalidad que su propio cron ignora al destruir
a esa misma persona esa noche.

**Qué pasa si nadie decide:** queda esa lectura. Es defendible, pero es una
lectura hecha por un desarrollador para resolver una ambigüedad del esquema.

**Quién contesta:** jurídica. Y la salida limpia no es interpretar el null: es
**declarar el plazo** de cada finalidad — o sea, el punto 1 de esta lista.

---

## 11. Quién es el titular en un proyecto nuevo

**Qué se decide:** cuando el scaffold genere un sistema nuevo, ¿sobre qué modelo
se implementa el titular de datos?

**Por qué no es obvio:** los sistemas municipales tienen `Persona` —el
ciudadano—. El scaffold solo tiene `User` —el funcionario del panel—. Los dos
son datos personales, con finalidades y régimen distintos, y el generador no
puede adivinar cuál corresponde.

**Qué pasa si nadie decide:** el scaffold no se integra, o peor: se integra
adivinando, y cada sistema nuevo nace con cumplimiento aparente.

**Quién contesta:** César. Recomendación escrita en
`docs/superpowers/plans/2026-08-17-scaffold-privacidad.md`: que el generador
pregunte, y que cuando no sepa deje un test rojo en vez de adivinar.
