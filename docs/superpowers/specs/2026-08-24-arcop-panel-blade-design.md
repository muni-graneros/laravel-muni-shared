# El ciclo ARCOP en cualquier Laravel: núcleo compartido y panel Blade

Fecha: 2026-08-24. Estado: diseño aprobado, pendiente de plan.

## El problema

El ciclo ARCOP de la Ley 21.719 —recibir una solicitud de un vecino y resolverla
con fundamento— hoy solo existe como **panel de Filament** (`PanelArcopPlugin` en
`laravel-muni-ui`). Un sistema sin Filament no lo puede heredar, y `atencionvecino`
—Laravel 12, Blade con controladores clásicos, Tailwind 3, sin Livewire ni
Filament— es exactamente ese caso.

Peor: `SolicitudResource` tiene 1115 líneas y no todo es presentación. Ahí adentro
viven reglas con consecuencia legal —el semáforo de plazo, la previa de supresión,
el aviso de separación de funciones, qué resultados se pueden elegir según el tipo
de solicitud, qué cesa de verdad—. Un segundo panel que las reescriba produce **dos
respuestas distintas para el mismo vecino según qué mesón lo atendió**.

## Lo que se construye

```
laravel-muni-shared  (dominio + reglas legales del ciclo)
        ▲                        ▲
laravel-muni-ui          laravel-arcop-panel     (paquete nuevo)
(panel Filament)         (panel Blade, sin JS)
        ▲                        ▲
  disc, licencias, …        atencionvecino
```

### Decisiones tomadas (y por qué)

| Decisión | Alternativa descartada | Razón |
|---|---|---|
| Panel Blade sin Filament | Montar Filament 5 en `atencionvecino` | El sistema ya tiene su panel Blade; meterle un segundo framework de panel para una sola función no se paga. |
| Paquete propio `laravel-arcop-panel` | Escribirlo dentro de `atencionvecino` | El objetivo es instalarlo con un tag en cualquier Laravel. Dentro del sistema, el segundo adoptante lo paga de nuevo. |
| Extraer las reglas a `muni-shared` **antes** de portar | Reescribirlas en el port | Dos implementaciones de una regla legal divergen, y divergir acá es certificarle por escrito a un vecino algo que no ocurrió. |
| Cero dependencias: ni Filament, ni Livewire, ni Tailwind, ni npm | Livewire empaquetado | Cada dependencia le resta alcance a «un tag y anda». Un panel con consecuencia legal que funciona sin JS es una virtud. |
| Páginas dedicadas por acción | Modales `<dialog>` | Un `<dialog>` necesita script para abrirse y choca con la CSP con nonce. Una página server-rendered atrapa el foco sola y no tiene ese problema. |
| Ciclo ARCOP completo en el piloto, con candado de cese real | Solo recepción y resolución | Tener la pantalla es la superficie del cumplimiento, no el cumplimiento. |

## Ciclo 0 — El núcleo, en `laravel-muni-shared`

Nace `Muni\Shared\Privacidad\Ciclo\`. Cada clase sale de `SolicitudResource` con
sus tests, y **devuelve datos, nunca presentación**: un estado semántico, no un
color de Filament ni una clase CSS.

| Clase | Qué se lleva del Resource | Nota |
|---|---|---|
| `PlazoLegal` | `etiquetaPlazo()`, `colorPlazo()` | Devuelve un enum `EstadoDePlazo` (Resuelta, Vencida, PorVencer, EnPlazo). El umbral de 5 días queda junto a `Solicitud::scopePorVencer()`, que hoy lo repite. El color lo elige cada panel. |
| `SeparacionDeFunciones` | `advertenciaSeparacionDeFunciones()` | Recibe el id de quien va a resolver por parámetro. No llama a `auth()`: el núcleo no supone que hay sesión. |
| `PreviaDeSupresion` | `previaDeSupresion()`, `antesDeSuprimir()` | Sigue delegando en `Supresiones::evaluar()`; lo que sube es la composición con el aviso. |
| `ResultadosDisponibles` | `resultadosDisponibles()`, `notaDeResultados()`, `etiquetaEstado()` | La regla de que una rectificación o una supresión no se «acogen» a mano es legal, no de UI. |
| `AlcanceDelCese` | `queCesaDeVerdad()`, `efectoSobreElBloqueo()` | El texto lo declara el adoptante; el default sigue diciendo que no se declaró. |
| `EtiquetaDeTitular` | `etiquetaTitular()`, `etiquetaDeTitular()`, `estaAnonimizada()` | Incluye los tres casos que el panel ya sabía distinguir: anonimizado, huérfano, y titular vivo. |
| `ResumenDeSupresion` | el texto de `avisarSupresion()` | Devuelve un objeto con los hechos (total/parcial, archivos, propagación aceptada), y cada panel lo redacta. La distinción «destruir el dato local ≠ sacar al vecino del ecosistema» vive en el objeto. |

También se muda el contrato `BuscaTitulares` de `Muni\Ui\Filament\Privacidad\Contratos`
a `Muni\Shared\Privacidad\Contratos`. Si los dos paneles buscan titulares distinto,
el vecino recibe respuestas distintas según el mesón.

**Criterio de que salió bien:** `muni-ui` v0.15.0 delega en el núcleo sin cambiar
comportamiento, y la suite de `discapacidad-graneros` pasa **sin tocar disc**. El
contrato viejo queda como alias deprecado para no romper a los adoptantes.

## Ciclo 1 — `muni-graneros/laravel-arcop-panel`

Dependencias: `php ^8.3`, `illuminate/support|routing|view|http`, y
`muni-graneros/laravel-muni-shared ^1.14`. Nada más.

**Rutas** (prefijo y middleware configurables en `config/arcop-panel.php`; por
defecto `privacidad` y el middleware `web`):

| Método y ruta | Para qué | Gate |
|---|---|---|
| `GET /solicitudes` | Bandeja con filtros de estado, tipo y plazo | `arcop.ver` |
| `GET /solicitudes/recibir` | Paso 1: buscar al titular | `arcop.recibir` |
| `POST /solicitudes/recibir` | Paso 2: formulario con el titular elegido | `arcop.recibir` |
| `POST /solicitudes` | Registrar la solicitud | `arcop.recibir` |
| `GET /solicitudes/{solicitud}` | Expediente en pantalla, con la bitácora | `arcop.ver` |
| `POST /solicitudes/{solicitud}/tomar` | Tomar el caso | `arcop.resolver` |
| `GET|POST /solicitudes/{solicitud}/resolver` | Acoger, acoger parcial o rechazar con fundamento | `arcop.resolver` |
| `GET|POST /solicitudes/{solicitud}/rectificar` | Un campo por dato rectificable, precargado | `arcop.resolver` |
| `GET|POST /solicitudes/{solicitud}/suprimir` | Con la previa de supresión a la vista | `arcop.resolver` |
| `GET /solicitudes/{solicitud}/expediente` | Descarga por stream | `arcop.ver` |

Los estados avanzan **solo por POST**, nunca por GET. Todo POST con CSRF.

**Autorización.** El paquete registra los tres gates **denegando por defecto**; el
adoptante los mapea a su sistema de permisos. Un gate sin mapear no abre nada. Los
tres son separables para el municipio que quiera separar recepción de resolución.

**Vistas.** `arcop-panel::` publicables (`--tag=arcop-panel-views`), y un
`layouts.app` propio que el adoptante puede reemplazar por el suyo desde la config
para que el panel quede dentro de su cascarón.

**CSS.** Autocontenido y publicable, escrito sobre tokens `--muni-*` con valores por
defecto propios: con `muni-ui` presente hereda la identidad municipal; sin él se ve
bien igual. Modo oscuro por `prefers-color-scheme` y por `.dark`/`data-muni-theme`,
igual que `muni-ui`.

**Lo que el paquete no hace:** decidir qué cesa. Eso lo declara cada adoptante, y
mientras no lo declare el panel lo dice en pantalla.

## Ciclo 2 — Piloto en `atencionvecino`

Solo código de adaptación:

- `Vecino implements TitularDeDatos` — nombre, documento, export, purga de datos
  sensibles, anonimización y campos rectificables (el RUT **no** es rectificable:
  eso lo acredita el Registro Civil).
- `BuscadorDeVecinos implements BuscaTitulares` — con mínimo de caracteres.
- **El candado de cese**: qué deja de tocar al vecino con un bloqueo vigente, sobre
  `Requerimiento`, `Evidencia`, `WhatsappConversacion` y `AuditoriaEvento`. Es la
  parte que hace que el sistema cumpla, y la que se verifica con tests.
- `VerificadorIdentidad` de mesón: RUN leído de la cédula.
- `RegistroDeEvidencia` sobre su `AuditoriaEvento`.
- Mapeo de los tres gates a sus `permisos_extra`, y el enlace en su panel.
- Subir `php` de `^8.2` a `^8.3` en su `composer.json` (la imagen ya corre 8.4).

## Seguridad y privacidad

- **Enumeración de RUT por el buscador de titulares** — es el agujero obvio del
  panel. Mínimo de caracteres, resultados acotados, throttle por usuario, y cada
  búsqueda queda en la bitácora. Devuelve lo mínimo para identificar, no la ficha.
- **IDOR** — cada acción resuelve la solicitud dentro del alcance del sistema
  adoptante, nunca por id a secas.
- **Expediente ARCOP** — por stream, jamás escrito en `public/`, y su descarga
  queda registrada: es la prueba de que el municipio atendió al vecino.
- **Evidencia inmutable** — la bitácora del módulo ya lo garantiza en base de datos;
  el panel no la puede editar.
- **Carga perezosa prohibida** — `exportarDatosPersonales()` toca varias relaciones
  y ya reventó una vez justo al descargar el expediente. `loadMissing()` y `with()`,
  con un test que fije `preventLazyLoading()` a mano.
- **Ley 21.719** — minimización en cada pantalla: la bandeja no muestra datos
  sensibles, y un caso anonimizado se muestra como tal, no como una fila rota.

## Accesibilidad (WCAG 2.2 AA — Decreto N°1/2015)

Formularios nativos con label asociado y error descrito en texto; foco visible;
área táctil de 24×24 mínimo; contraste 4.5:1 verificado en **ambos** modos; sin
color como único portador de información —el semáforo de plazo lleva texto—;
recorrido completo por teclado.

## Pruebas

- `muni-shared`: Pest sobre cada clase del núcleo. `composer test:mariadb`
  obligatorio antes de tagear.
- `muni-ui`: la suite existente tiene que pasar sin cambios de comportamiento.
- `laravel-arcop-panel`: Testbench + Pest. Un test HTTP por ruta, un 403 por cada
  gate sin mapear, throttle del buscador, separación de funciones, y el expediente.
- `atencionvecino`: PHPUnit 11 (su suite), Feature tests del adaptador y del
  candado de cese —que el bloqueo vigente efectivamente apague cada tratamiento—.
- Verificación en vivo antes de dar nada por terminado: capturas en claro y oscuro,
  escritorio y móvil, los cuatro estados interactivos y navegación por teclado.

## Criterios de aceptación

1. `discapacidad-graneros` sigue verde sin haber sido tocado.
2. En un Laravel 12/13 pelado, `composer require` + tres gates + un `TitularDeDatos`
   dan un panel ARCOP funcionando, sin instalar nada de npm.
3. En `atencionvecino`, un bloqueo vigente apaga de verdad los cuatro tratamientos,
   demostrado con tests.
4. Cero reglas legales duplicadas entre el panel de Filament y el de Blade.

## Lo que queda fuera

Consentimientos, textos informativos, brechas, RAT y retención no tienen pantalla
en este ciclo: hoy tampoco la tienen en Filament. El panel Blade se limita al ciclo
ARCOP.
