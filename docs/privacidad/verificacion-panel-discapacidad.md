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

## Lo que NO se pudo verificar

**La interfaz.** Los dos MCP de navegador disponibles (`playwright` y
`chrome-devtools`) se disputan el mismo perfil de Chrome y ninguno respondió tras
cuatro intentos. Así que queda sin comprobar en vivo: el render del recurso de
solicitudes, el widget de plazos en el dashboard, los avisos del panel —incluido
el que distingue «supresión aplicada» de «supresión aplicada solo en este
sistema»— y la navegación por teclado.

Todo eso tiene tests (416 en verde), pero este proyecto ya demostró dos veces que
la suite verde no cubre lo que el navegador ve: un panel devolviendo 403 con
tests verdes, y un 404 fantasma por `octane:reload`. **Queda pendiente y hay que
hacerlo antes de dar el panel por terminado.**
