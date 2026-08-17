# Verificación en vivo del módulo Privacidad (Ley 21.719) en discapacidad-graneros

Fecha: 2026-08-16
Sistema: `discapacidad-graneros`, rama `feat/privacidad-21719`, HEAD `5384461`
Entorno: Docker (FrankenPHP + **MariaDB 11**), BD de desarrollo `graneros` (27 personas reales de demo)
BD aislada usada para el camino destructivo: **`graneros_test`** (la misma que usa `make test`), sembrada
con 20.027 personas sintéticas. **Nunca se corrió `--ejecutar` contra `graneros`.**

Esto no es un checklist verde. La suite ya estaba en 339 verdes, Pint y PHPStan nivel 8 limpios antes de
empezar; lo que sigue es lo que apareció al correr el módulo contra el esquema, los datos y la
configuración reales. **Aparecieron once cosas.** Cuatro de ellas son bloqueantes para producción y las
cuatro se van a repetir en los otros siete sistemas, porque no son propias de discapacidad.

---

## Lo que se ejecutó

| Comando | BD | Resultado |
|---|---|---|
| `db:seed --class=FinalidadesPrivacidadSeeder` | `graneros` | 6 finalidades, idempotente (re-sembrar no duplica) |
| `privacidad:rat` | `graneros` | tabla completa + aviso de encargado sin contrato |
| `privacidad:rat --json` | `graneros` | JSON válido y parseable |
| `privacidad:aplicar-retencion` (simulación) | `graneros` | «No hay titulares con plazo vencido» — **verificado en BD**: 47/47 checksums idénticos |
| `privacidad:aplicar-retencion` (simulación) | `graneros_test` 20k | 5 filas, 5,9 s |
| `privacidad:aplicar-retencion --ejecutar` | `graneros_test` 20k | 3 corridas, ver hallazgos 1, 4, 5, 6 y 9 |
| `privacidad:rat` / `aplicar-retencion` con `PRIVACIDAD_SISTEMA=` | `graneros` | ver hallazgo 8 |
| `aplicar-retencion --ejecutar` con `PRIVACIDAD_DISCO_EVIDENCIA=` | `graneros_test` | ver hallazgo 11 (**esto sí funciona**) |

El módulo expone exactamente **dos** comandos (`php artisan list | grep privacidad`), ninguna ruta y
ningún recurso Filament en este sistema.

---

## Salidas reales

### `privacidad:rat`

```
RAT del sistema «discapacidad» — I. Municipalidad de Graneros
+------------------------+-----------------------------------------------+--------------------------------+------------------------------------------------------------------+-------------------------------------+-------------------+---------+
| Código                 | Finalidad                                     | Base de licitud                | Norma                                                            | Causal dato sensible                | Retención (meses) | Estado  |
+------------------------+-----------------------------------------------+--------------------------------+------------------------------------------------------------------+-------------------------------------+-------------------+---------+
| agenda_citas           | Agendamiento de citas                         | Ejercicio de funciones legales | LOC de Municipalidades, art. 4 letra c)                          | —                                   | 24                | Vigente |
| atencion_social        | Atenciones y seguimiento de casos             | Ejercicio de funciones legales | Ley 20.422, art. 8; LOC de Municipalidades, art. 4 letra c)      | Fines estatales habilitados por ley | 60                | Vigente |
| ayudas_tecnicas        | Entrega de ayudas técnicas                    | Ejercicio de funciones legales | Ley 20.422, art. 8                                               | Fines estatales habilitados por ley | 60                | Vigente |
| comunicaciones         | Comunicaciones y difusión de beneficios       | Consentimiento del titular     | —                                                                | —                                   | 24                | Vigente |
| registro_comunal       | Registro comunal de personas con discapacidad | Ejercicio de funciones legales | Ley 20.422, arts. 1 y 8; LOC de Municipalidades, art. 4 letra c) | Fines estatales habilitados por ley | 120               | Vigente |
| sincronizacion_maestro | Sincronización con el maestro de personas     | Ejercicio de funciones legales | LOC de Municipalidades, art. 4 letra c)                          | —                                   | sin plazo         | Vigente |
+------------------------+-----------------------------------------------+--------------------------------+------------------------------------------------------------------+-------------------------------------+-------------------+---------+
Encargados sin contrato al día: Maestro de Personas. La ley exige contrato con cada encargado del tratamiento.
El sistema «discapacidad» no declara decisiones automatizadas con efectos significativos sobre los titulares.
```

El aviso de encargado sí dispara. La declaración de «ninguna decisión automatizada» sí se emite en vez
de callar.

### `privacidad:rat --json`

Parsea (`json.load` sin error). Claves: `sistema`, `generado_en`, `responsable`, `finalidades`,
`decisiones_automatizadas`. Resumen del contenido:

```
agenda_citas             base=funcion_legal  exc=None                                cats=['identificacion','contacto']                                        enc=[]
atencion_social          base=funcion_legal  exc=fines_estatales_habilitados_por_ley  cats=['identificacion','salud','discapacidad']                            enc=[]
ayudas_tecnicas          base=funcion_legal  exc=fines_estatales_habilitados_por_ley  cats=['identificacion','salud','discapacidad']                            enc=[]
comunicaciones           base=consentimiento exc=None                                cats=['identificacion','contacto']                                        enc=[]
registro_comunal         base=funcion_legal  exc=fines_estatales_habilitados_por_ley  cats=['identificacion','contacto','salud','discapacidad','socioeconomico'] enc=[]
sincronizacion_maestro   base=funcion_legal  exc=None                                cats=['identificacion','contacto']                                        enc=['Maestro de Personas']

responsable: {'nombre': 'I. Municipalidad de Graneros', 'contacto': '', 'delegado': ''}
destinatarios: null en las seis
decisiones_automatizadas: []
```

### `privacidad:aplicar-retencion` (simulación, BD real)

```
Modo simulación: no se modificará ningún dato. Usar --ejecutar para aplicar.
No hay titulares con plazo de retención vencido.
```

**No se creyó al comando.** Antes de correrlo se tomó `CHECKSUM TABLE ... EXTENDED` de las 47 tablas de
`graneros`; después se repitió y el `diff` salió vacío: 47/47 idénticos. Es correcto además por los
datos: las 27 personas tienen `fecha_registro` entre 2026-06-02 y 2026-06-09, ninguna supera ni el plazo
más corto (24 meses), y ninguna tiene `fecha_registro` nula.

> Nota de honestidad sobre «nada cambió»: horas después, un `diff` de control mostró que
> `graneros.personas` sí cambió de checksum. Se persiguió hasta el fondo y **no** es la retención: la
> única columna que se movió es `sincronizado_maestro_at` (las 27 filas a `2026-08-16 19:53:57`), que
> escribe el cron `personas:resincronizar` (cada 15 min) con `DB::table()->update()` justamente para no
> tocar `updated_at` — que sigue en `2026-08-13`. `nro_documento`, `nombres`, `apellidos` y el resto
> intactos; 0 personas anonimizadas en `graneros`. La evidencia que sostiene la afirmación es el diff
> tomado en la ventana inmediata alrededor del comando, no este.

Contra volumen (20.027 personas en `graneros_test`), la misma simulación:

```
+------------------+-----------+
| Finalidad        | Titulares |
+------------------+-----------+
| agenda_citas     | 14000     |
| atencion_social  | 9779      |
| ayudas_tecnicas  | 9779      |
| comunicaciones   | 14000     |
| registro_comunal | 2483      |
+------------------+-----------+
real 0m5,902s
```

---

## Hallazgos

Ordenados por gravedad. Los cuatro primeros bloquean producción.

### 1 — BLOQUEANTE. Anonimizar en un sistema NO anonimiza en el maestro: crea una persona nueva y deja la real intacta

`AplicarRetencion` llama `$titular->purgarDatosSensibles()` y `$titular->anonimizar()`; los dos hacen
`->save()` sobre `Persona`. Este sistema tiene un observador `Persona::saved` (`AppServiceProvider:194`)
que despacha el write-through `SincronizarPersonaAlMaestro`. O sea: **cada anonimización dispara dos
POST al maestro de personas.**

El maestro hace upsert por RUT, y `anonimizar()` reemplaza el RUT por el centinela `ANON-{id}`. Resultado
verificado en la BD del maestro (`personas-graneros`, tabla `personas`), 120 filas creadas en 60
anonimizaciones:

```
id     nro_documento   nombres       apellidos     created_at
12897  10000034-4      Prueba34      Volumen34     2026-08-16 19:55:36   <- el purgar empujó a la persona REAL
12898  ANON-34         ANONIMIZADO   ANONIMIZADO   2026-08-16 19:55:36   <- el anonimizar creó una persona NUEVA
12899  10000035-5      Prueba35      Volumen35     2026-08-16 19:55:36
12900  ANON-35         ANONIMIZADO   ANONIMIZADO   2026-08-16 19:55:36
...
```

Las dos consecuencias, y las dos son graves:

1. **La supresión no es efectiva a nivel de ecosistema.** El `payload()` del job manda
   `nro_documento, tipo_documento, nombres, apellidos, fecha_nacimiento, sexo, telefono, email,
   direccion, sector`. La identidad completa de la persona que la retención acaba de suprimir localmente
   queda viva en el maestro, y por tanto sigue disponible para feria, licencias, control-acceso y todo lo
   que consulte el maestro por RUT. Decirle a la Agencia «suprimimos» sería falso.
2. **Peor todavía: la retención EMPUJA la identidad real al maestro justo antes de borrarla.** Fíjese en
   el orden de arriba: `purgarDatosSensibles()` guarda primero, con la persona aún íntegra → el maestro
   la crea/refresca (id 12897). Recién después `anonimizar()` la vuelve `ANON-34`. Si esa persona no
   estaba en el maestro, la retención la **da de alta ahí**. Es exactamente lo contrario de lo que el
   comando existe para hacer.
3. Y el maestro queda con basura: un `ANON-{id}` por anonimización, que va a aparecer en el autocompletar
   por RUT de todos los sistemas.

Ni el módulo ni el paquete de write-through saben el uno del otro. Ninguno de los dos está mal por
separado; juntos producen esto, y **el write-through está en los ocho sistemas**. Es el hallazgo que hay
que resolver en el paquete antes de tocar el sistema número dos.

La suite no lo puede ver, y conviene saber por qué: hay un test verde que dice
«el payload al maestro SOLO lleva identidad/contacto, nunca datos sensibles» — y es cierto. Nadie testea
la interacción, porque cada mitad se prueba sola y las dos pasan. Solo aparece corriendo el comando
completo contra un maestro real.

Lo mínimo que hace falta: que `anonimizar()` corra sin disparar el observador (`saveQuietly()` /
`withoutEvents`), y que exista un camino explícito «propagar la anonimización al maestro» que el maestro
entienda como supresión del registro existente, no como alta de uno nuevo.

> **Deuda que dejé:** esas 120 filas siguen en el maestro de desarrollo (`personas-graneros`, ids
> 12897–13016, todas con `created_at = 2026-08-16`, todas con `nro_documento` `10000%` o `ANON-%`; nada
> más se creó ni se actualizó ese día). Intenté borrarlas y el clasificador de permisos bloqueó el DELETE
> contra la BD de otro repo — correctamente. Queda para correr a mano:
> `delete from personas where id between 12897 and 13016;`

### 2 — BLOQUEANTE. El plazo más corto se lleva puesto a los demás: `agenda_citas` (24 meses) destruye el `registro_comunal` (120 meses)

`AplicarRetencion` recorre finalidad por finalidad y, para cada titular vencido, llama
`$titular->anonimizar()`. Pero `anonimizar()` **no recibe la finalidad** y es global a la `Persona`: borra
RUT, nombres, apellidos, teléfono, email, dirección, tutor y coordenadas de una vez.

Efecto: alguien vencido para la finalidad de plazo más corto queda anonimizado por completo, aunque otra
finalidad vigente exija conservarlo. En `graneros_test`, **11.517 personas** estaban fuera de la ventana
de 24 meses de `agenda_citas` pero dentro de la de 120 meses de `registro_comunal`. Comprobado en la
corrida destructiva: persona id 34, `fecha_registro = 2018-12-16` (92 meses, o sea a mitad de camino del
plazo del registro comunal), quedó `nro_documento = ANON-34`, `nombres = ANONIMIZADO`.

Dicho de otra forma: hoy los `plazo_retencion_meses` de 60 y 120 del RAT no significan nada. El único
número que opera es el mínimo de la tabla. Y el RAT le declara a la autoridad que el registro comunal se
conserva 10 años.

Es estructural del contrato del paquete: `TitularDeDatos::anonimizar()` no tiene alcance por finalidad, y
`ResuelveTitularesVencidos::vencidos(Finalidad)` recibe la finalidad pero el resolvedor de este sistema
solo le saca el plazo. Se arregla en los dos lados o no se arregla.

### 3 — BLOQUEANTE. Nadie corre la retención: no está en el `schedule`

`php artisan schedule:list` en este sistema lista 9 tareas (recordatorios, credenciales, backups,
resincronización, archivado de auditoría, prune de colas). **`privacidad:aplicar-retencion` no está.**
`privacidad:rat` tampoco.

O sea que hoy la obligación de suprimir cuando el dato ya no es necesario se cumple solo si alguien se
acuerda de tipear el comando. El módulo está instalado, migrado, sembrado y con los contratos enlazados —
y no se ejecuta nunca. Es el paso que nadie escribió: el README del módulo describe los comandos pero la
adopción no incluyó agendarlos.

Cuidado al agendarlo, porque interactúa con los hallazgos 4 y 5: un cron diario tal como está hoy tumba
el maestro a 429 y corre más de 10 minutos.

### 4 — BLOQUEANTE. La corrida destructiva muere a mitad y la constancia se pierde si el proceso lo matan

Primera corrida `--ejecutar` sobre `graneros_test`, con el maestro habilitado (o sea, la configuración
real):

```
   Illuminate\Http\Client\RequestException
  HTTP request returned status code 429:
  { "message": "Too Many Attempts." }
  2  /laravel-muni-shared/src/Persona/WriteThrough/SincronizarAlMaestro.php:122
  25 app/Providers/AppServiceProvider.php:194
real 0m7,554s
```

7,5 segundos y 60 de ~14.000 titulares. El rate limit del maestro corta la corrida entera: no hay
aislamiento por titular a nivel de comando, ni reanudación, ni `--limite`, ni barra de progreso.

Acá el `finally` de `AplicarRetencion` sí cumplió: escribió
`retencion.constancia {"titulares":60,"filas":60,"archivos_suprimidos":0,"archivos_no_encontrados":0}`.

**Pero el `finally` solo protege contra excepciones, no contra que maten el proceso.** Segunda corrida
(maestro deshabilitado con `PERSONA_RESOLVER=local`): a los 10 minutos la maté por timeout. Estado
después: **10.131 personas anonimizadas, 10.132 `retencion.aplicada`, 10.132 `bitacora.desvinculada`… y
cero constancias nuevas.** Diez mil supresiones sin la constancia agregada que el diseño promete.

Y no es un caso exótico: a ~17 personas/segundo, una primera corrida sobre el backlog real de un registro
comunal dura tanto que morir por timeout, OOM, reinicio de deploy o Ctrl-C es el desenlace *probable*, no
el raro. El comentario del `finally` en `AplicarRetencion.php` argumenta el caso «el titular número siete
revienta»; el caso que de verdad pasa es «el proceso número uno no llega al final».

Lo bueno: la corrida **sí es reanudable** de hecho, porque el resolvedor excluye
`nro_documento not like 'ANON-%'`. Lo que falta es que la constancia se escriba por lote, no una vez al
final.

### 5 — El comando no tiene candado: dos corridas simultáneas se pisan y MariaDB tira error 1020

`docker compose exec` que expira **no mata el proceso dentro del contenedor**: después del timeout de 10
minutos, `ps aux` seguía mostrando `php artisan privacidad:aplicar-retencion --ejecutar` (pid 62056)
corriendo. Al lanzar otra corrida encima:

```
SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'personas';
try restarting transaction
(SQL: update `personas` set `nro_documento` = ANON-16201, ... where `id` = 16201)
```

Es específico de MariaDB y no aparece en la suite (SQLite, un solo proceso). El comando no declara
`WithoutOverlapping` ni `onOneServer`. Con la duración del hallazgo 4, un cron diario **va a** solaparse
consigo mismo, y cada solapamiento aborta una corrida a mitad — que por el hallazgo 4 es una corrida sin
constancia.

### 6 — El resumen de la simulación cuenta la misma persona varias veces

La tabla de la simulación suma 50.041 «titulares» sobre 20.027 personas. Un funcionario que la lea antes
de autorizar el `--ejecutar` va a creer que hay 50 mil personas por suprimir. El resumen es por finalidad
y no dice —ni en el encabezado ni en un total— que los conjuntos se superponen. Junto con el hallazgo 2
(que dice que en realidad se van a destruir *todas* las de la finalidad más corta) el resumen es
directamente engañoso como pantalla de confirmación.

### 7 — El RAT sub-declara `agenda_citas`: hay dato de salud sin causal

`agenda_citas` declara `categorias_datos = ['identificacion','contacto']` y `excepcion_dato_sensible =
null`. Contra el esquema real, la tabla `citas` guarda `motivo` (texto libre), `motivo_cierre` (texto
libre), `tipo_atencion_id` y `profesional`. En un sistema de discapacidad, el motivo de la cita y el tipo
de atención son dato de salud. El RAT no declara la categoría ni la causal del art. 16.

Las tres finalidades que sí declaran `salud`/`discapacidad` (`registro_comunal`, `atencion_social`,
`ayudas_tecnicas`) sí traen su causal `fines_estatales_habilitados_por_ley`: ahí está bien. El agujero es
justo el de plazo más corto, que además es el que gobierna toda la retención (hallazgo 2).

### 8 — Fallar en silencio por el lado del JSON

Con `PRIVACIDAD_SISTEMA` vacío:

- `privacidad:rat` (tabla): avisa fuerte, «El sistema «» no declaró ninguna finalidad de tratamiento».
- `privacidad:aplicar-retencion`: avisa fuerte, «…no se revisó nada. Sembrar las finalidades…».
- `privacidad:rat --json`: **no avisa nada.** Emite un documento sintácticamente válido, completo,
  con `"sistema": ""`, `"finalidades": []`, `"decisiones_automatizadas": []`, y **sale con código 0.**

El chequeo de `--json` va antes que el aviso a propósito (para no ensuciar la salida de quien
redirige a `json_decode`) — pero el efecto es que un tablero de cumplimiento, el hub o una entrega a la
autoridad reciben un RAT bien formado que dice «este sistema no trata datos», indistinguible de un
sistema correctamente configurado que efectivamente no declara nada. Falta un `"advertencias": []` en el
JSON, o un exit code distinto de 0.

### 9 — `responsable.contacto` y `responsable.delegado` van vacíos al RAT y nadie chista

`PRIVACIDAD_CONTACTO=` y `PRIVACIDAD_DELEGADO=` están presentes-pero-vacíos en el `.env`, y el RAT los
emite como `""`. La ley pide identificar al responsable y su punto de contacto. Ningún comando avisa.

Es el mismo modo de falla que `disco_evidencia` tenía y que se arregló (`env()` solo aplica el default
cuando la clave está *ausente*): presente-y-vacía se ve idéntica a configurada para quien lee el `.env`.
Vale la pena aplicar el mismo criterio: o avisa el comando, o el RAT no se exporta sin contacto.

### 10 — Cosas menores, para no perderlas

- **`fecha_registro` no tiene índice.** `EXPLAIN` del resolvedor: `type=ALL`, `possible_keys=NULL`,
  19.358 filas. Scan completo de `personas`, por cada finalidad con plazo (4 veces). A 20k son 5,9 s;
  escala lineal.
- **La simulación hidrata Eloquent solo para contar.** `vencidos()` devuelve `cursor()` y
  `AplicarRetencion` hace `$contados++` sobre cada modelo. 50 mil hidrataciones para producir 5 números;
  un `count()` haría lo mismo.
- **`persona_lookups.rut_buscado_norm` la puebla el evento `saving` del modelo, no la base.** Cualquier
  fila insertada con `DB::table()->insert()` queda con `rut_buscado_norm = NULL` y `anonimizar()` nunca
  la borra: el RUT buscado sobrevive a la anonimización, en silencio. Lo comprobé al sembrar. La
  migración `2026_08_16_000001` sí hace backfill de las filas previas, así que el riesgo es solo para
  escrituras futuras que salteen el modelo.
- **`privacidad_consentimientos` no tiene columna `sistema`** (`privacidad_solicitudes` sí). Hoy no
  molesta porque cada sistema tiene su BD, pero rompe la simetría del módulo.
- **El RAT en JSON no emite `Encargado.pais` ni `medidas`**, que sí están en el esquema. Justo lo que se
  pregunta para transferencia internacional. Tampoco emite advertencias (ver hallazgo 8).
- **`destinatarios` va `null` en las seis finalidades**, incluida `sincronizacion_maestro`, que
  literalmente tiene un `Encargado` declarado y manda PII a otro sistema. La pregunta «¿a quién le
  comunican los datos?» queda sin responder en el RAT.
- **El módulo no tiene superficie de operador en este sistema**: cero recursos Filament, cero rutas
  (`route:list --except-vendor` filtrado por `privac|arcop|consentim` no devuelve nada). Solicitudes
  ARCOP, consentimientos, brechas, informaciones y bloqueos solo se pueden tocar desde código. Un
  funcionario del mesón no puede registrar una solicitud de acceso. El Plan 2 del módulo (panel) no es un
  extra: sin él, lo adoptado es un esquema.
- **La BD «aislada» no aísla las salidas.** `make test` cambia `DB_DATABASE` pero no toca
  `PERSONA_RESOLVER`; por eso una corrida en `graneros_test` escribió 120 filas en el maestro real de
  desarrollo (hallazgo 1). Para los otros siete sistemas: aislar también `PERSONA_RESOLVER=local`.

### 11 — Lo que SÍ funcionó, verificado y no asumido

Vale la pena dejarlo escrito porque son justo los puntos que la suite no podía probar:

- **El borrado de archivos funciona en el disco `sensibles` real.** Se preparó un titular con cinco
  archivos reales (`consentimiento_path` de `personas`, `credencial_senadis_path` y
  `certificado_medico_path` de `persona_discapacidades`, `evidencia_path` de
  `privacidad_consentimientos`, `respuesta_path` de `privacidad_solicitudes`). Después de la corrida:
  directorio vacío, las cinco columnas en `NULL`, `descripcion` en `NULL`, `persona_lookups` en 0,
  `titular_id` en `NULL` y `titular_ref` con el token opaco (`p1Fn1REq7…`). **La configuración
  `PRIVACIDAD_DISCO_EVIDENCIA=sensibles` del `.env` es correcta y está probada en vivo.**
- **La guarda `DiscoEvidenciaNoConfigurado` hace exactamente lo que promete.** Con
  `PRIVACIDAD_DISCO_EVIDENCIA=` vacío y un documento por borrar, truena antes de tocar nada y la
  transacción revierte: persona `10016582-2` **no** anonimizada, `evidencia_path` intacto, archivo
  intacto en disco. Verificado, no leído.
- **El `finally` de la constancia sobrevive a una excepción** (probado con el 429: 60 titulares, 1
  constancia). Lo que no sobrevive es que maten el proceso (hallazgo 4).
- **El seeder de finalidades es idempotente**: re-sembrar deja 6 filas, no 12.
- **Todas las migraciones del paquete corrieron en MariaDB 11** sin tocar el esquema (14 migraciones,
  `migrate:status` todas en `Ran`). No hubo que parchear nada acá.
- **La simulación no modifica nada**, comprobado con `CHECKSUM TABLE EXTENDED` de las 47 tablas antes y
  después, no creyéndole al resumen del comando.

---

## Qué se llevan los otros siete sistemas

Lo que es propio de discapacidad (el seeder de finalidades, el resolvedor por atención/cita) se vuelve a
escribir en cada sistema. Lo que **no** es propio y va a reaparecer tal cual:

| # | Hallazgo | Alcance |
|---|---|---|
| 1 | Anonimizar dispara el write-through: crea `ANON-{id}` en el maestro y deja la identidad real viva | Los 8 sistemas con write-through. **Arreglar en el paquete antes de adoptar el segundo.** |
| 2 | `anonimizar()` sin alcance por finalidad: gana el plazo más corto | Contrato del paquete |
| 3 | Los comandos no se agendan solos | Toda adopción |
| 4 | La constancia se escribe una vez al final; el proceso no llega al final | Paquete |
| 5 | Sin `WithoutOverlapping`; error 1020 de MariaDB al solaparse | Paquete |
| 6 | Resumen de simulación que cuenta doble | Paquete |
| 8 | `--json` sin advertencias ni exit code | Paquete |
| 9 | `responsable.contacto`/`delegado` vacíos sin aviso | Toda adopción |
| 10 | Sin índice en la columna de corte; sin superficie de operador; BD de test que no aísla las salidas | Toda adopción |

Los hallazgos 7 (sub-declaración del RAT) y 9 hay que revisarlos con la jefatura: el seeder de
finalidades de este sistema **queda pendiente de revisión del municipio y no es definitivo**.
