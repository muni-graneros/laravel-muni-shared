# Patrón del panel municipal: qué se hizo en `discapacidad-graneros`

Fecha: 2026-08-24. Origen: llevar `discapacidad-graneros` al nivel de
`web-graneros` y, donde se pudo, más allá.

Este documento existe para **copiar el patrón** en los otros sistemas y en el
scaffold. No es una bitácora: cada punto dice qué se cambió, por qué, y —cuando
lo hay— el defecto medido que lo motivó.

Convención: ✅ = comprobado midiendo; ⚠️ = pendiente o no verificado.

---

## 1. Seguridad del panel

### 1.1 El panel de Filament no hereda el grupo `web` ✅

`SecurityHeaders` se agregaba en `bootstrap/app.php` al grupo `web`, y **un panel
de Filament declara su propia pila**. Medido con `curl -I`: el sitio devolvía
`Content-Security-Policy-Report-Only`; el panel, **ninguna cabecera**.

O sea que la pantalla donde se administran datos personales era la única sin
política de contenido. Es el mismo mecanismo por el que el MFA no protegía nada
en nueve sistemas.

**Patrón:** `SecurityHeaders::class` **primero** en el `->middleware([...])` del
panel, no solo en el grupo `web`.

### 1.2 La política pasa de avisar a bloquear ✅

`CSP_ENFORCE` a `true`. Al encenderla apareció una fuga real: Filament pedía el
avatar a `ui-avatars.com/api/?name=C+D`, o sea **mandaba el nombre del
funcionario a un tercero en cada carga**, un tratamiento que ningún RAT declara.

**Patrón:** proveedor de avatar local que dibuja las iniciales en un `data:` SVG.
Devolver `null` desde `getFilamentAvatarUrl()` NO alcanza: Filament lo lee como
«usá el proveedor por defecto».

### 1.3 Tope de vida de la sesión ✅

Middleware que cierra la sesión a las 8 horas y ante cambio de prefijo de red.
Va **después** de `DispatchServingFilamentEvent` en la pila del panel.

### 1.4 La salida quedaba exenta por una ruta que no existe ✅

El middleware del segundo factor eximía `$request->is('logout')`. En ese sistema
las salidas son `salir`, `discapacidad/logout` y la del SSO: **ninguna quedaba
exenta**, así que con MFA encendido quien no pudiera verificarse quedaba
atrapado sin poder cerrar sesión.

**Patrón:** comprobar la exención contra `route:list`, no contra la costumbre.
Eximir por nombre (`filament.*.auth.logout`) y por las rutas propias.

### 1.5 No eximir a Livewire del segundo factor ✅

Por Livewire viajan las acciones del panel —resolver, borrar, editar—, que son
justo lo que hay que proteger. El middleware es persistente
(`addPersistentMiddleware`) y Livewire reconstruye la petición con la ruta que
originó el componente, así que eximir la pantalla de verificación ya basta.

---

## 2. Identidad visual compartida

### 2.1 El escritorio de fábrica trae dos columnas ✅

Con dos columnas ningún widget puede declarar que ocupa un tercio: todo cae a
ancho completo o a la mitad, y el escritorio se estira. **Patrón:** página
`Escritorio extends Dashboard` con `getColumns()` de 6 (`default 1`, `md 2`,
`xl 6`), y cada widget declarando su `$columnSpan` **según la forma del dato**
—una serie de tiempo necesita ancho; una dona no—.

Excluirla en `config/filament-shield.php`: es la primera pantalla tras
identificarse y gatearla deja a quien no tenga el permiso sin dónde entrar.

### 2.2 Los stats se reparten de a tres ✅

Dos tarjetas dejan un tercio vacío; cinco bajan la última a otra fila. **Patrón:**
declarar `protected int|array|null $columns` con el número real.

### 2.3 Los gráficos sin tope estiran el lienzo ✅

Un mes sin datos gastaba una pantalla entera dibujando una línea plana.
**Patrón:** `$maxHeight` fijo y **el mismo** en los gráficos de una misma fila.

### 2.4 Paleta y tipografía ✅

`Color::generateV3Palette('#355a63')` e `->font('Inter')`. El `#355a63` es el
`--mg-petroleo` del cinturón del escudo: **el primario sale del escudo**, no de
una elección aparte.

El crema del lienzo (`#f8f5ec`) y las tarjetas blancas **no se escriben en el
tema**: los pone `muni-ui`. Por eso el modo oscuro coincide solo entre sistemas
(`#081418` cuerpo, `#0f2025` secciones).

**Patrón:** el tema del sistema solo lleva lo FUNCIONAL. Todo lo decorativo que
duplique a `muni-ui` es deuda: el de este sistema bajó de 261 a 97 líneas.

### 2.5 Las pantallas fuera del panel se olvidan ✅

Ingreso propio, recuperación y segundo factor viven fuera de Filament y fuera de
`muni-ui`: el cambio de paleta del panel **no las toca**. Las de seguridad
estaban sobre un degradado azul sin ningún signo del municipio — quien venía del
panel aterrizaba en algo que parecía otro sistema, justo en el paso en que se le
pide un código.

**Patrón:** entrypoint `resources/css/auth.css` que importa `muni-ui` y fija el
acento, y un `layouts/app.blade.php` con `<x-muni::gob-stripe>`, escudo y pie.

**No** adoptar `x-muni::auth-shell`: no lo usa nadie, es un diseño a dos columnas
que ningún panel usa, y arrastra un defecto del `<title>`.

### 2.6 Modales desde la orilla ✅

`->slideOver()` en las acciones **con formulario**: un diálogo centrado se
dimensiona por su contenido y queda recortado. Las confirmaciones de dos líneas
se dejan centradas.

### 2.7 Gotcha: `animation-fill-mode: both` ancla los `position: fixed` ✅

`both` retiene el último fotograma, que declara `transform`. Aunque sea la
identidad, **crea bloque contenedor** y los modales salen encajonados bajo la
barra superior. Usar `backwards`.

---

## 3. Accesibilidad (WCAG 2.2 AA — Decreto N°1/2015)

### 3.1 Vigilar el panel, no solo la app ✅

El test que había cubría la app operativa y dejaba el panel entero sin vigilancia
—justo las pantallas con consecuencia legal—, y apuntaba a WCAG 2.1.

**Patrón:** `e2e/tests/a11y-panel.spec.ts` con `wcag22aa`, recorriendo cada
pantalla en **modo claro y oscuro**, y auditando los modales **abiertos**
(cerrados, su contenido no está en el árbol y el escaneo pasa sin mirar nada).
Más `a11y-auth.spec.ts` para las pantallas de acceso, que **corre sin sesión**:
con sesión redirigen al panel y se mediría otra página.

### 3.2 Defectos que encontró ✅

| Defecto | Medido | Mínimo |
|---|---|---|
| Enlace teal sobre blanco | 3,74:1 | 4,5:1 |
| Migas zinc-500 sobre el crema | 4,42:1 | 4,5:1 |
| Botón de cerrar avisos | **sin nombre accesible** | — |

Las migas fallan porque **Filament calcula su gris contra SU fondo blanco** y el
lienzo de `muni-ui` es crema. Es el precio de cambiar la paleta sin revisar lo
que el framework calculó contra la suya.

El botón de cerrar de las notificaciones lo arma Filament v5 en PHP solo con el
icono, sin `aria-label` ni texto: nombre accesible vacío, impacto **crítico**.
**Patrón:** registrar un icono propio para el alias
`notifications::notification.close-button` que devuelva el mismo SVG más un
`<span>` de solo-lectores. Filament v5 **no** trae clase `fi-sr-only`: hay que
definirla. Y el helper `generate_icon_html` vive en `Filament\Support`, no en el
espacio global: sin el `use function` rompe TODAS las notificaciones del panel.

### 3.3 Defecto abierto ⚠️

El select con buscador pinta `<label for="form.titular_id">` apuntando a un id
**que no existe**. El control real es un `<button>` cuyo nombre accesible es
«Seleccione una opción». Es de Filament; no se arregló.

---

## 4. Rendimiento

### 4.1 Los estáticos no pasan por PHP ✅

Un middleware de Laravel para cachearlos **no hace nada**: los sirve Caddy
directamente. Medido pidiendo `/build/assets/app-*.js` con el middleware puesto:
200 sin ninguna cabecera.

**Patrón:** las cabeceras van en el Caddyfile. `/build/*` y las fuentes a un año
con `immutable`; el resto de estáticos a una semana. **Nada por extensión de
documento** (`*.pdf`): en un sistema municipal un PDF puede ser el expediente de
un vecino, y `public` autoriza a guardarlo a cualquier intermediario.

### 4.2 Gotcha: `CADDY_SERVER_EXTRA_DIRECTIVES` puede no sustituirse ✅

En `web-graneros` funciona; en `discapacidad-graneros` **no**, con el mismo
Octane (v2.17.5), el mismo stub, el mismo entrypoint y la variable presente en el
entorno del proceso 1. Medido contra la config viva de Caddy
(`localhost:2019/config/`): ningún manejador de cabeceras, ni con `import` ni con
la directiva en línea. No se encontró la causa.

**Patrón robusto:** `octane:start --caddyfile=/etc/caddy/octane.caddyfile` con
una copia del stub y las cabeceras dentro. Anotar que hay que compararla al subir
Octane, porque no se actualiza sola.

### 4.3 Modo SPA ✅

`->spa()` con `->unsavedChangesAlerts()`. Verificar en vivo: hay gotchas
documentados (scripts duplicados, `->slideOver()` que no abre con página de
creación propia).

---

## 5. Segundo factor

**Decisión del municipio:** código de seis dígitos por correo, como
`web-graneros`. De los seis sistemas, **cinco usaban TOTP**: el que se salía de
la norma era web-graneros.

**Queda dicho:** el código por correo es un factor **más débil**. Llega al mismo
buzón que recibe el enlace de recuperar contraseña, así que quien tome esa
casilla obtiene los dos factores de una vez.

Lo que vale la pena copiar de la implementación:

- Tope de intentos **ANTES** de comparar el código. Son 900.000 valores y quien
  ya pasó la contraseña puede probarlos sin freno durante los diez minutos.
- `hash_equals`: no filtrar por tiempo de respuesta cuántos dígitos acertó.
- La notificación **en cola**: si el correo tarda, quien entra no mira una
  pantalla congelada.
- El código **nunca** en los registros ni en `toArray()` de la notificación.
- Correo enmascarado en pantalla (`ce•••@dominio`): confirmar adónde fue el
  código es útil; publicar la casilla entera en una oficina compartida, no.
- Si se abandona TOTP, **borrar** `two_factor_secret`: conservar un secreto que
  ya nadie usa es tratar datos sin finalidad (Ley 21.719).

---

## 6. Trampas al portar entre repos

- **`RefreshDatabase` vs `DatabaseTransactions`.** Copiar tests de otro sistema
  trae su estilo de aislamiento. En una suite que corre sobre **BD sembrada**,
  `RefreshDatabase` hace `migrate:fresh` una vez y borra lo que otros archivos
  necesitan: **144 tests en rojo** por copiar cinco archivos.
- **Un test que se salta en silencio.** El del modal se saltaba cuando la tabla
  estaba vacía: verde sin haber mirado nunca. Que cree su dato — y que **reuse**
  si ya existe, o acumula (dejó nueve solicitudes ARCOP idénticas en la BD de
  desarrollo).
- **Los repos `path` con `symlink: false` son copias.** Editar el paquete no
  llega al `vendor/` del adoptante: hay que reinstalar, y `composer update`
  puede servir la copia cacheada.
- **Afirmar de más.** Se midió `role="dialog"` en el elemento equivocado y se
  concluyó que Filament no lo ponía; está en el ancestro. Y se leyó un clic de
  Playwright como «el SPA rompió el login» cuando solo faltaba esperar.

---

## 7. Carga perezosa prohibida ✅

Con `preventLazyLoading`, cada relación que se toque sin precargar es un 500.
`exportarDatosPersonales()` tocaba cinco y reventaba justo al **descargar el
expediente ARCOP**, que es la prueba de que el municipio atendió la solicitud.

**Patrón:** `loadMissing()` para las del propio modelo y `->with()` en cada
consulta anidada. Y un test que fije `Model::preventLazyLoading()` **a mano**,
para que siga cazándolo aunque alguien lo apague en el ambiente de pruebas.
