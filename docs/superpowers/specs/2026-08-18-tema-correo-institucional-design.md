# Tema de correo institucional en muni-shared

Fecha: 2026-08-18
Estado: aprobado, pendiente de plan de implementación

## El problema

Los correos del ecosistema salen con la plantilla genérica de Laravel. Incluye
los códigos de verificación en dos pasos, que hoy son el correo más importante
que manda el municipio: sin él, un funcionario no entra al sistema.

Inventario medido el 2026-08-18 sobre los nueve repositorios:

| Repositorio | `mail.markdown.theme` | Vistas en `resources/views/emails` |
|---|---|---|
| feria-graneros | `graneros` | 5 |
| discapacidad-graneros | `graneros` | 8 |
| web-graneros | `default` | 3 |
| licencias-graneros | sin bloque `markdown` | 5 |
| credenciales-graneros | sin bloque `markdown` | 3 |
| seguridad-graneros | sin bloque `markdown` | 3 |
| control-acceso-graneros | sin bloque `markdown` | 3 |
| scaffold-laravel-filament-pwa | sin bloque `markdown` | 3 |
| plataforma-graneros | sin bloque `markdown` | 0 |

Siete de nueve mandan correo con el tema por defecto del framework. Los cinco
sin bloque `markdown` caen en un defecto ya conocido del ecosistema: desde
Laravel 11 la ausencia de una clave en `config/*.php` no da error, se resuelve
al valor por omisión y nadie se entera.

El tema `graneros` sí existe, pero solo porque alguien lo copió a mano en dos
repositorios. Los dos árboles de dieciocho archivos son idénticos salvo una
línea de comentario que nombra el panel de cada sistema.

Las vistas están igual de duplicadas. Comparadas por hash:

- `bienvenida.blade.php` y `restablecer-contrasena.blade.php`: idénticas en los
  siete repositorios que las tienen.
- `mfa.blade.php`: dos variantes, una en feria y discapacidad, otra en los
  cinco restantes.

Veintiún archivos que son cuatro contenidos.

Y el paquete ya promete lo que no cumple. El docblock de
`Muni\Shared\SystemNotification` dice que «el logo, los colores y el pie salen
del tema graneros», pero el paquete no trae ese tema: la promesa solo se cumple
donde alguien copió los archivos.

## Defectos del tema actual

Medidos sobre `feria-graneros/resources/views/vendor/mail/html/themes/graneros.css`.

**Contraste.** Dos pares no alcanzan el 4.5:1 que exige WCAG 2.2 AA para texto,
obligatorio en sistemas del Estado por el Decreto N°1 de 2015 de SEGPRES:

| Elemento | Colores | Ratio | Veredicto |
|---|---|---|---|
| Texto del pie | `#8a94a6` sobre blanco, 12px | 3.06:1 | falla |
| Texto del botón | blanco sobre `#0d9488` | 3.74:1 | falla |
| Cuerpo | `#4a5568` sobre blanco | 7.53:1 | pasa |
| Títulos | `#1a3a5f` sobre blanco | 11.57:1 | pasa |
| Cuerpo sobre panel | `#4a5568` sobre `#eef2f7` | 6.69:1 | pasa |

**Colores no institucionales.** El azul `#1a3a5f` y el verde azulado `#0d9488`
no pertenecen a la identidad municipal. El petróleo del escudo es `#355a63`, y
su variante oscura `#00404c`.

**Sin modo oscuro.** Cero reglas `prefers-color-scheme` en las 309 líneas.

**Sin firma institucional.** No hay franja de siete colores ni escudo: solo un
`logo-graneros.png` servido por URL absoluta desde `config('app.url')`.

## Decisiones

1. **Extraer y mejorar**, no rediseñar. El tema existente es la base; se
   corrigen sus defectos.
2. **Cabecera con franja dibujada en CSS y escudo opcional encima.** Gmail,
   Outlook y Apple Mail bloquean imágenes remotas por omisión. Con la franja
   dibujada, un correo con las imágenes bloqueadas sigue siendo reconociblemente
   municipal. Hay además una razón de privacidad: una imagen remota le confirma
   al servidor que el correo fue abierto y desde qué dirección IP, un dato
   personal recogido sin base de licitud clara bajo la Ley 21.719.
3. **El paquete sirve el tema; los repositorios pueden publicarlo.** Nadie copia
   nada por omisión y `composer update` propaga las correcciones. Quien necesite
   desviarse publica sus archivos y esos ganan.
4. **Alcance: tema más las tres vistas de autenticación**, instalado y
   verificado en web-graneros primero, y adoptado después en los ocho sistemas
   restantes. La adopción entró al alcance el 2026-08-18, después de escribir
   este documento; se hace por sistema, cada uno con su prueba de envío.
5. **Modo oscuro a prueba de inversión y con reglas explícitas.** Apple Mail y
   Outlook moderno respetan `prefers-color-scheme`; Gmail invierte por su cuenta
   ignorando esas reglas. Se cubren los dos casos.

## Arquitectura

El paquete gana `resources/views/mail/`, con los dieciocho componentes del tema
—las ocho vistas HTML, las ocho de texto plano, y `themes/default.css` y
`themes/graneros.css`— más `resources/views/emails/auth/` con las tres vistas de
autenticación.

`MuniSharedServiceProvider` añade en `register()`:

- La ruta del paquete al principio de `mail.markdown.paths`. Verificado en
  `Illuminate/Mail/Markdown.php:236`: `componentPaths()` mergea las rutas
  configuradas antes de la del framework, de modo que el paquete gana sobre el
  tema por defecto y lo publicado en `resources/views/vendor/mail` gana sobre
  el paquete.
- `mail.markdown.theme` igual a `graneros`, **solo si el repositorio no definió
  el suyo**. Un sistema que ya eligió tema no se ve alterado por actualizar el
  paquete.

  Consecuencia concreta: los cinco repositorios sin bloque `markdown` adoptan el
  tema con solo actualizar. web-graneros no, porque declara `'theme' => 'default'`
  de forma explícita: ahí hay que cambiar esa línea a mano, y esa es la única
  edición que la instalación le hace a su configuración.

Y en `boot()`, un `publishes()` bajo la etiqueta `muni-mail-views`.

El paquete no publica ningún asset de imagen. En web-graneros la carpeta
`public/vendor/muni-ui/` contiene `logo-graneros.png` junto a `filament.css`,
que es CSS del panel: publicar imágenes contra esa ruta podría sobrescribirlo.
El escudo se referencia por URL a la copia ya publicada.

## Diseño visual

### Cabecera

Una tabla de tres filas, porque las tablas son lo único que Outlook renderiza de
forma predecible: usa el motor de Word.

1. **Franja institucional**: siete celdas de 6px de alto, cada una con su color
   de fondo, en el orden oficial: lima `#adcd60`, petróleo `#355a63`, oro
   `#eab02c`, naranja `#c76421`, celeste `#7ccbe1`, carmín `#ca3048`, gris
   `#9c9b9b`. Son los mismos valores que `--muni-gob-*` en `laravel-muni-ui`.
   El componente `x-muni::gob-stripe` los pinta con `linear-gradient`, que en
   correo no sirve: de ahí las celdas. Los hex se mantienen idénticos para que
   panel y correo se vean iguales.
2. **Escudo**: PNG por URL absoluta, alto máximo 56px, con texto alternativo.
3. **Nombre del sistema**: texto, en petróleo oscuro.

La franja lleva `role="presentation"` y no aporta información que no esté en el
texto: es decoración, y el color no es portador único de nada.

### Paleta

| Rol | Antes | Ahora | Contraste sobre blanco |
|---|---|---|---|
| Títulos | `#1a3a5f` | `#00404c` | 11.43:1 |
| Cuerpo | `#4a5568` | `#355a63` | 7.52:1 |
| Fondo del botón | `#0d9488` | `#355a63` | 7.52:1 con texto blanco |
| Texto del pie | `#8a94a6` | `#5d6b6f` | 5.53:1 |
| Error | `#b03030` | `#b03030` | 6.34:1 |
| Éxito | `#0f7a5a` | `#0f7a5a` | 5.31:1 |

Los dos defectos de contraste quedan corregidos por el propio cambio a colores
institucionales.

### Modo oscuro

Un bloque `@media (prefers-color-scheme: dark)` para los clientes que lo
respetan, con fondo `#0f1a1d`, superficie `#16262a` y texto `#e8eef0`. Para los
que invierten por su cuenta, ningún color del tema cae en la franja media donde
la inversión produce texto casi blanco sobre fondo casi blanco. La franja se
declara con `!important` en cada celda: es el elemento que más sufre la
inversión automática y el que identifica al municipio.

## Vistas de autenticación

`emails.auth.mfa`, `emails.auth.bienvenida` y `emails.auth.restablecer-contrasena`
pasan al paquete. `SystemNotification::correo()` resuelve primero la vista local
del repositorio y cae a la del paquete si no existe, de modo que ningún sistema
cambia de comportamiento al actualizar: quien tenga vista propia la conserva.

Para que un sistema adopte las del paquete debe borrar las suyas. Es un paso
deliberado y explícito, no un efecto de actualizar.

Se corrige el docblock de `SystemNotification`, que hoy describe un tema que el
paquete no traía.

De las dos variantes de `mfa.blade.php` se adopta la de feria y discapacidad,
que es la que ya convive con el tema institucional.

## Pruebas

En el paquete:

- El tema queda registrado y `mail.markdown.paths` incluye la ruta del paquete.
- Un repositorio que ya definió `mail.markdown.theme` conserva el suyo.
- La vista del paquete se resuelve cuando el repositorio no la tiene.
- La vista local gana cuando existe.
- Cada uno de los tres correos renderiza sin excepción y su HTML contiene los
  siete colores de la franja.
- Un test de contraste que recalcula los pares críticos desde el CSS y falla si
  alguno baja de 4.5:1. Es lo que impide que el defecto vuelva a entrar.

En web-graneros:

- Los tres correos renderizados y capturados en claro y oscuro, escritorio y
  móvil: cuatro capturas por correo.
- Un envío real con `php artisan correo:probar --a=`, para verlo en un cliente
  de verdad. En local el escudo no cargará porque `APP_URL` apunta a localhost:
  ese es justamente el escenario de imagen bloqueada, y sirve como prueba de que
  la cabecera aguanta sin él.

## Fuera de alcance

- Unificar las vistas de contenido propias de cada sistema, más allá de las tres
  de autenticación.
- El paquete `laravel-muni-ui` y el diseño del panel.
- `RolesSeeder`, que crea un usuario con rol `super_admin` y contraseña
  `password` en cada ejecución de `env:provision`. Es un defecto de seguridad
  real y urgente, pero es de los repositorios y no de este paquete: se arregla
  aparte.

## Riesgos

**Feria y discapacidad cambian de aspecto, y hay que capturarlo.** Son los dos
sistemas que hoy usan el tema copiado. Mientras conserven sus copias publicadas
no cambia nada, porque lo publicado gana sobre el paquete; el cambio ocurre en
el momento exacto en que se borran esas copias. Ahí heredan la versión
corregida: otro color de botón y de pie. Es el objetivo, pero pide capturas de
antes y después, y son los dos únicos sistemas donde alguien podría notar la
diferencia de un día para otro.

**Entre la publicación del paquete y la adopción conviven dos temas `graneros`.**
El del paquete y el copiado en feria y discapacidad, que ya no son iguales. Es
transitorio y esperado, pero explica por qué dos sistemas se ven distintos
mientras dura.

**El escudo depende de `APP_URL`.** Si un sistema lo tiene mal en producción, la
imagen no carga. La cabecera está diseñada para sobrevivir a eso, así que
degrada en vez de romperse.

**La entrega no arregla el DMARC del dominio.** `municipalidadgraneros.cl`
publica `p=quarentine`, con una errata que anula la política. Un correo puede
verse perfecto y aun así terminar en no deseados. Es un problema de DNS, ajeno a
este trabajo, pero condiciona cómo se interpreta la prueba de envío.
