# Verificación en vivo del ciclo ARCOP en discapacidad

Fecha: 2026-08-17
Sistema: `discapacidad-graneros`, rama `feat/privacidad-21719`, corriendo en Docker
contra MariaDB real y contra el maestro de personas real (`personas-api:8000`).

No es una corrida de tests: es el sistema levantado, con los datos que tiene, y el
registro federado del ecosistema al otro lado.

## Cómo se hizo, y por qué así

Se creó una persona de prueba con RUT falso (`99.999.999-9`, válido en dígito
verificador) y **una segunda persona de control sin ninguna solicitud**. Las dos
recorrieron el mismo código en el mismo ambiente; lo único distinto entre ellas
es el bloqueo. Sin ese control, todo lo que sigue habría sido inatribuible — y de
hecho el primer intento lo fue (ver «Errores del verificador»).

Al terminar se purgaron las dos personas de `discapacidad` y del maestro, y las
filas de solicitudes, bloqueos y bitácora. El ambiente quedó como estaba: una
sola fila `ANON-34`, que venía de la verificación del 2026-08-16.

## Lo que se comprobó

### 1. La evaluación de la supresión, contra el RAT real

```
procedeTotal=false   esParcial=true   noProcede=false
impiden = agenda_citas, atencion_social, ayudas_tecnicas, registro_comunal
cesan   = comunicaciones, sincronizacion_maestro
```

Es la confirmación en vivo de lo que estaba escrito como advertencia: **con el RAT
sembrado hoy, una persona registrada este año no obtiene una supresión total.**
Cuatro finalidades obligan a conservar; dos cesan. `registro_comunal` a 120 meses
es la que manda.

### 2. Aplicar la supresión no destruyó nada, que es lo correcto

Tras `Supresiones::aplicar()`:

- La persona quedó **intacta** — RUT y nombres leídos de la fila después.
- Dos bloqueos **definitivos** (`levantado_en` nulo), uno por cada finalidad que
  cesa, y los dos con `sistema = discapacidad`: el aislamiento por sistema que se
  arregló hoy en el módulo funciona en la escritura, no solo en la consulta.
- La solicitud quedó `acogida_parcial`.
- `ResultadoDeSupresion::$propagacion` vino en `null`, que es el diseño: no se
  destruyó nada, así que no había nada que propagarle al maestro.

Bitácora, en orden: `solicitud.registrada` → `bloqueo.aplicado` (×2) →
`solicitud.acogida_parcial` → `supresion.parcial`, esta última con la norma
citada de cada finalidad que impide.

### 3. El candado, que es lo que esta verificación vino a probar

Las dos personas, mismo job, mismo ambiente:

| Persona | `loAceptoElMaestro` | En el maestro, por RUT |
|---|---|---|
| Con cese de `sincronizacion_maestro` | **false** | **404** |
| Control, sin bloqueo | **true** | **200** |

La bloqueada **nunca llegó al registro federado**. La de control sí, y quedó
consultable por RUT desde los ocho sistemas del ecosistema hasta que se purgó.

Esto es lo que hasta ayer no existía: el panel sellaba la solicitud y el sistema
seguía empujando a la persona al maestro igual. Hoy el cese detiene el dato antes
de que salga del sistema, y está medido, no razonado.

### 4. El RAT y la retención, ejecutados

`privacidad:rat` produce el registro completo y **avisa lo que le falta** —
encargado sin contrato al día, y el responsable incompleto (ver hallazgo 1).

`privacidad:aplicar-retencion` corre **en simulación por defecto** y exige
`--ejecutar` para tocar algo. Es el default correcto para un comando destructivo.
Su respuesta hoy es «no hay titulares con plazo de retención vencido», coherente
con los 120 meses de `registro_comunal`.

## Hallazgos

### 1. Falta el contacto para ejercer derechos y el delegado de protección de datos

`privacidad:rat` lo dice en cada ejecución:

> El responsable del tratamiento está incompleto: falta contacto para ejercer
> derechos, delegado de protección de datos.

`PRIVACIDAD_CONTACTO` y `PRIVACIDAD_DELEGADO` no están puestos en este ambiente.
No es cosmético: **la ley exige identificar al responsable y la vía por la que el
titular ejerce sus derechos**, y sin eso el vecino no tiene a dónde escribir. Es
configuración, se arregla en el `.env`, y hay que decidir qué correo y qué persona
van ahí — o sea que es del municipio, no del código.

### 2. El RAT sigue sin nombrar las tres salidas de datos

Confirmado leyendo la salida del comando: las seis finalidades declaradas no
cubren la geocodificación contra Nominatim, la API que entrega fichas por RUT a
los otros sistemas, ni el resumen por IA de las atenciones. Está escrito en
`decisiones-del-municipio.md`, punto 11.

### 3. Nunca se había registrado una solicitud ARCOP

La solicitud de esta prueba quedó con `id = 1`. Dicho de otro modo: el ciclo
completo existía, estaba probado, y **jamás había sido usado con datos reales**
hasta hoy. Era exactamente el motivo por el que se construyó el panel.

## Errores del verificador, anotados a propósito

Dos, y los dos habrían producido un informe falso si no se hubieran perseguido:

1. **Se consultó `localhost:8000` creyendo que era el maestro.** Es
   `discapacidad`, que tiene su propio `PersonaServicioController`. Los dos 404
   que dio no probaban nada, y a primera vista parecían probar que el candado
   funcionaba.
2. **Se consultó el maestro sin el prefijo `api/`.** El 404 resultante se leyó
   por un momento como «el endpoint del maestro está roto para todos». No lo
   está: el cuerpo era HTML y no el JSON del controlador, que es lo que delató
   que era una ruta inexistente y no una persona no encontrada.

Se dejan escritos porque son el mismo error de forma que este proyecto persigue
hace catorce hallazgos: **una observación verificada en una dimensión y enunciada
en otra más ancha.** La diferencia acá es que se cazaron a tiempo, y lo que las
cazó fue tener una persona de control.

## La interfaz, verificada con navegador

Se recorrió el panel como lo haría una funcionaria del mesón, con Playwright
contra el sistema corriendo.

### El ciclo completo funciona

Registrar → tomar → resolver, todo por pantalla:

| Paso | Resultado observado |
|---|---|
| Recepción con RUN correcto | Solicitud creada, redirige al listado, fila con semáforo «En plazo» |
| Tomar (modal de confirmación) | `estado = en_tramite`, aviso «Solicitud en trámite.» |
| Resolver acogiendo, con fundamento | `estado = acogida`, `resuelta_en` y `user_resolucion` escritos |

El buscador de titular por RUT encuentra a la persona, y el listado muestra las
cinco columnas con el vencimiento y el titular con su RUT.

### Las tres negativas llegan con el mensaje del módulo, no con uno genérico

- **RUN con dígito verificador inválido** → «La cédula presentada no acredita al
  titular… (el RUN presentado no es válido)».
- **RUN válido de otra persona** → mismo encabezado, pero **mensaje distinto**:
  «(el RUN presentado **no corresponde al titular**)». Las dos negativas se
  distinguen, que es lo que el funcionario necesita para saber qué hacer.
- **Resolver sin fundamento** → «Toda resolución debe ir fundada: es lo que se le
  responde al titular.» Es literalmente el texto del módulo: la decisión de NO
  poner `required()` en el formulario —para que no lo reemplace un «campo
  obligatorio» genérico— se sostiene en pantalla.

En los tres casos **no se escribió ninguna fila**, comprobado contando la tabla
después de cada intento.

### La separación de funciones se ve en los datos

La solicitud quedó con `user_registro_id = 1` (quien la recibió) y
`user_resolucion_id = 2` (quien la resolvió). Son personas distintas porque el
rol `administrador` **no tiene** `resolver_solicitud_arcop` y `Coordinación
Discapacidad` sí. Con el administrador, la columna «Acciones» del listado sale
vacía; con coordinación aparecen Tomar, Resolver y Descargar el expediente.

Funciona, pero conviene decidirlo a conciencia: el seeder describe a
`administrador` como super-admin de Shield, y no lo es para este permiso. Si es
deliberado, está bien; si no, el administrador no puede atender una solicitud.

### El widget de plazos

Aparece en el escritorio con sus dos tarjetas enlazadas al listado filtrado
—vencidas y por vencer—. **No se veía al principio**, por dos causas de ambiente
y ninguna de código: los permisos no estaban sembrados en esta base de
desarrollo, y el caché de componentes de Filament era del 14 de agosto, anterior
al widget. El entrypoint de producción cubre las dos (`db:seed --class=PermisosRolesSeeder`
y `filament:optimize`), así que no llega a producción.

## Hallazgo del ambiente: los contenedores `worker` y `reverb` no montaban el paquete

El `docker-compose.yml` montaba `../laravel-muni-shared:/laravel-muni-shared:ro`
**solo en el servicio `app`**. Los otros dos resolvían el symlink de `vendor/` a
un destino inexistente, no podían cargar el service provider del paquete y
morían al arrancar, escribiendo el error en el log compartido —que es lo único
que delataba el problema—.

Consecuencias, las dos silenciosas: **la cola no procesaba nada** y **Reverb no
aceptaba conexiones** (los `ERR_CONNECTION_RESET` del WebSocket que al principio
se tomaron por ajenos eran esto). Se arregló agregando el montaje a los dos
servicios, y al recrearlos la cola drenó sola los write-through pendientes —tanto
que la persona de prueba llegó al maestro y hubo que purgarla—.

Es andamio temporal del repositorio `path`: desaparece cuando el paquete se
publique. Mientras tanto, cualquiera que levante esta rama tiene cola y tiempo
real caídos sin aviso.

## Otro residuo confirmado en vivo

**`tomar()` no deja entrada en la bitácora.** Tras el ciclo completo hay dos
—`solicitud.registrada` y `solicitud.acogida`— y ninguna del momento en que
alguien se hizo cargo. Estaba anotado como pendiente del módulo; acá se ve.

## Errores del verificador, anotados a propósito

Cuatro, y todos habrían producido un informe falso si no se hubieran perseguido:

1. **Se consultó `localhost:8000` creyendo que era el maestro.** Es
   `discapacidad`, que tiene su propio `PersonaServicioController`. Los dos 404
   que dio parecían probar que el candado funcionaba.
2. **Se consultó el maestro sin el prefijo `api/`.** El 404 se leyó por un
   momento como «el endpoint del maestro está roto para todos». Lo que lo delató
   fue que el cuerpo era HTML y no el JSON del controlador.
3. **Se usaron dos RUT inválidos creyéndolos válidos** (`16.111.222-3` y
   `99.999.998-7`), así que la primera «prueba de RUN que no corresponde» en
   realidad probó otra cosa. Lo delató que el mensaje decía «no es válido» y no
   «no corresponde al titular».
4. **Se dio por defecto que el widget no se veía**, cuando faltaba sembrar
   permisos y refrescar el caché de componentes en esta base.

Se dejan escritos porque son el mismo error de forma que este proyecto persigue
hace catorce hallazgos: **una observación verificada en una dimensión y enunciada
en otra más ancha.** La diferencia es que se cazaron a tiempo.

## Lo que queda sin verificar

- **Modo oscuro, móvil y navegación completa por teclado** del recurso ARCOP y su
  widget. Se capturó el listado en escritorio y modo claro.
- **La descarga del expediente** (derecho de acceso y portabilidad): el botón
  está y es visible con el rol correcto, pero no se ejerció la descarga.
