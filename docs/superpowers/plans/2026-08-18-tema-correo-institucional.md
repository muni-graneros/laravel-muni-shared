# Tema de correo institucional Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que los nueve sistemas del ecosistema manden correo con la identidad de la Municipalidad de Graneros, servida desde `laravel-muni-shared`, sin copiar archivos en cada repositorio y sin los dos defectos de contraste que arrastra el tema actual.

**Architecture:** El paquete pasa a servir los componentes de correo, el tema CSS y las tres vistas de autenticación. Registra su ruta en `mail.markdown.paths`, que Laravel antepone a la del framework, de modo que lo publicado en un repositorio sigue ganando sobre el paquete. Primero se construye y se verifica en web-graneros; después se adopta en los ocho restantes.

**Tech Stack:** PHP 8.3, Laravel 12/13, Orchestra Testbench, PHPUnit, Blade, CSS para clientes de correo (tablas, sin flexbox ni gradientes), Microsoft Graph como transporte.

**Spec:** `docs/superpowers/specs/2026-08-18-tema-correo-institucional-design.md`

## Global Constraints

- Umbral de contraste: **4.5:1** para texto (WCAG 2.2 AA, exigible por el Decreto N°1 de 2015 de SEGPRES).
- Paleta institucional, valores exactos, idénticos a los `--muni-gob-*` de `laravel-muni-ui`: lima `#adcd60`, petróleo `#355a63`, petróleo oscuro `#00404c`, oro `#eab02c`, naranja `#c76421`, celeste `#7ccbe1`, carmín `#ca3048`, gris `#9c9b9b`.
- Orden de la franja, de izquierda a derecha: lima, petróleo, oro, naranja, celeste, carmín, gris.
- **Nada de `linear-gradient`, flexbox, grid ni `position` en el correo.** Outlook usa el motor de Word. Todo va con tablas y atributos.
- El paquete **no publica ningún asset de imagen**. En web-graneros, `public/vendor/muni-ui/` contiene `filament.css` del panel: publicar contra esa ruta podría sobrescribirlo.
- Colores del tema: títulos `#00404c`, cuerpo `#355a63`, fondo de botón `#355a63` con texto blanco, pie `#5d6b6f`, error `#b03030`, éxito `#0f7a5a`.
- Commits en español, sin atribución a ninguna IA, bajo la cuenta `buguenocesar92`.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `resources/views/mail/html/*.blade.php` (crear, 8) | Componentes HTML del correo |
| `resources/views/mail/text/*.blade.php` (crear, 8) | Equivalentes en texto plano |
| `resources/views/mail/html/themes/graneros.css` (crear) | El tema: paleta, tipografía, modo oscuro |
| `resources/views/mail/html/themes/default.css` (crear) | Copia del tema base de Laravel, que los componentes esperan encontrar |
| `resources/views/emails/auth/*.blade.php` (crear, 3) | Las tres vistas de autenticación compartidas |
| `src/MuniSharedServiceProvider.php` (modificar, `register()` y `boot()`) | Registro de rutas, tema y publicación |
| `src/SystemNotification.php` (modificar) | Resolución de vista con respaldo, y el docblock que hoy miente |
| `tests/Correo/TemaTest.php` (crear) | Registro, prioridad y contraste |
| `tests/Correo/VistasAuthTest.php` (crear) | Render de las tres vistas y respaldo |

---

### Task 1: El tema, servido desde el paquete

**Files:**
- Create: `resources/views/mail/**` (los 18 archivos), copiados desde `feria-graneros/resources/views/vendor/mail/`
- Modify: `src/MuniSharedServiceProvider.php` (`register()`)
- Test: `tests/Correo/TemaTest.php`

**Interfaces:**
- Consumes: nada de tareas anteriores.
- Produces: la ruta `__DIR__.'/../resources/views/mail'` registrada en `mail.markdown.paths`, y el tema `graneros` disponible. Las tareas 2 y 3 escriben dentro de esa ruta.

- [ ] **Step 1: Copiar el tema existente sin modificarlo**

```bash
mkdir -p resources/views/mail
cp -a ../feria-graneros/resources/views/vendor/mail/. resources/views/mail/
ls -R resources/views/mail | head -25
```

Se copia tal cual, sin retocar: la Tarea 2 aplica los cambios de diseño sobre una base que ya se sabe que funciona. Copiar y rediseñar en el mismo paso hace imposible saber cuál de los dos rompió algo.

- [ ] **Step 2: Escribir la prueba que falla**

Crear `tests/Correo/TemaTest.php`:

```php
<?php

namespace Muni\Shared\Tests\Correo;

use Muni\Shared\Tests\TestCase;

class TemaTest extends TestCase
{
    public function test_el_paquete_registra_su_ruta_de_componentes(): void
    {
        $rutas = config('mail.markdown.paths', []);

        $delPaquete = array_filter(
            $rutas,
            static fn (string $ruta): bool => str_contains($ruta, 'laravel-muni-shared')
                || str_contains($ruta, realpath(__DIR__.'/../../resources/views/mail') ?: 'imposible')
        );

        $this->assertNotEmpty($delPaquete, 'El paquete no registró su ruta en mail.markdown.paths');
    }

    public function test_el_tema_por_omision_es_graneros(): void
    {
        $this->assertSame('graneros', config('mail.markdown.theme'));
    }

    public function test_el_archivo_del_tema_existe(): void
    {
        $this->assertFileExists(__DIR__.'/../../resources/views/mail/html/themes/graneros.css');
    }
}
```

Si el paquete no tiene todavía `tests/TestCase.php`, crearlo:

```php
<?php

namespace Muni\Shared\Tests;

use Muni\Shared\MuniSharedServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MuniSharedServiceProvider::class];
    }
}
```

- [ ] **Step 3: Correr la prueba para verla fallar**

Run: `vendor/bin/phpunit --filter=TemaTest`
Expected: los dos primeros casos FALLAN; el tercero pasa porque el Paso 1 copió el archivo.

- [ ] **Step 4: Registrar la ruta y el tema**

En `src/MuniSharedServiceProvider.php`, dentro de `register()`, justo después de los `mergeConfigFrom` existentes:

```php
        // Los componentes del correo se sirven desde el paquete. Laravel antepone
        // estas rutas a la suya (Illuminate\Mail\Markdown::componentPaths), así que
        // el paquete gana sobre el tema por omisión del framework, y lo que un
        // repositorio publique en resources/views/vendor/mail gana sobre el paquete.
        config([
            'mail.markdown.paths' => array_merge(
                [__DIR__.'/../resources/views/mail'],
                config('mail.markdown.paths', []),
            ),
        ]);

        // El tema solo se impone si el repositorio no eligió el suyo: actualizar el
        // paquete no debe cambiarle el aspecto del correo a quien ya decidió.
        if (config('mail.markdown.theme') === null) {
            config(['mail.markdown.theme' => 'graneros']);
        }
```

Cuidado: va en `register()` y no en `boot()`, por la misma razón que el bloque del mailer que ya está arriba — la configuración tiene que estar completa antes de que alguien resuelva el correo.

- [ ] **Step 5: Correr la prueba y verla pasar**

Run: `vendor/bin/phpunit --filter=TemaTest`
Expected: los tres casos PASAN.

- [ ] **Step 6: Añadir la publicación**

En `boot()`, dentro del bloque `if ($this->app->runningInConsole())` que ya existe:

```php
            $this->publishes([
                __DIR__.'/../resources/views/mail' => resource_path('views/vendor/mail'),
            ], 'muni-mail-views');
```

- [ ] **Step 7: Commit**

```bash
git add resources/views/mail tests/ src/MuniSharedServiceProvider.php
git commit -m "feat(correo): el paquete sirve los componentes del correo

Hasta ahora el tema institucional solo existía copiado a mano en feria y
discapacidad, y SystemNotification decía que lo traía el paquete. Ahora es
verdad. Se copia tal cual: el rediseño va aparte."
```

---

### Task 2: Cabecera institucional, paleta y modo oscuro

**Files:**
- Modify: `resources/views/mail/html/header.blade.php`
- Modify: `resources/views/mail/html/themes/graneros.css`
- Test: `tests/Correo/TemaTest.php` (añadir casos)

**Interfaces:**
- Consumes: la ruta del paquete registrada en la Tarea 1.
- Produces: la clase CSS `.franja` y las celdas con `bgcolor`, que la Tarea 4 verifica en las capturas.

- [ ] **Step 1: Escribir las pruebas que fallan**

Añadir a `tests/Correo/TemaTest.php`:

```php
    private function css(): string
    {
        return file_get_contents(__DIR__.'/../../resources/views/mail/html/themes/graneros.css');
    }

    private function ratio(string $fg, string $bg): float
    {
        $lum = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $canales = array_map(static function (string $par): float {
                $c = hexdec($par) / 255;

                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, str_split($hex, 2));

            return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
        };

        $a = $lum($fg);
        $b = $lum($bg);

        return round((max($a, $b) + 0.05) / (min($a, $b) + 0.05), 2);
    }

    public function test_el_texto_del_boton_pasa_AA(): void
    {
        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#ffffff', '#355a63'));
        $this->assertStringContainsString('#355a63', $this->css());
    }

    public function test_el_pie_pasa_AA(): void
    {
        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#5d6b6f', '#ffffff'));
        $this->assertStringContainsString('#5d6b6f', $this->css());
    }

    public function test_no_quedan_colores_del_tema_anterior(): void
    {
        $css = $this->css();

        $this->assertStringNotContainsString('#0d9488', $css, 'Sigue el verde azulado que no es institucional');
        $this->assertStringNotContainsString('#8a94a6', $css, 'Sigue el gris del pie que no pasaba AA');
        $this->assertStringNotContainsString('#1a3a5f', $css, 'Sigue el azul que no es institucional');
    }

    public function test_el_tema_declara_modo_oscuro(): void
    {
        $this->assertStringContainsString('prefers-color-scheme: dark', $this->css());
    }

    public function test_la_cabecera_lleva_los_siete_colores_de_la_franja(): void
    {
        $header = file_get_contents(__DIR__.'/../../resources/views/mail/html/header.blade.php');

        foreach (['#adcd60', '#355a63', '#eab02c', '#c76421', '#7ccbe1', '#ca3048', '#9c9b9b'] as $color) {
            $this->assertStringContainsString($color, $header, "Falta {$color} en la franja");
        }
    }

    public function test_la_cabecera_no_usa_css_que_outlook_no_entiende(): void
    {
        $header = file_get_contents(__DIR__.'/../../resources/views/mail/html/header.blade.php');

        $this->assertStringNotContainsString('linear-gradient', $header);
        $this->assertStringNotContainsString('display:flex', str_replace(' ', '', $header));
    }
```

- [ ] **Step 2: Correr las pruebas para verlas fallar**

Run: `vendor/bin/phpunit --filter=TemaTest`
Expected: FALLAN las de botón, pie, colores anteriores, modo oscuro y franja.

- [ ] **Step 3: Reescribir la cabecera**

Sustituir el contenido de `resources/views/mail/html/header.blade.php`:

```blade
@props(['url'])
<tr>
<td class="header">
{{-- La franja institucional va en celdas de tabla con bgcolor, y no con
     linear-gradient como en el panel: Outlook renderiza con el motor de Word y
     no dibuja gradientes. Los siete valores son los mismos --muni-gob-* de
     laravel-muni-ui, para que panel y correo se vean iguales. --}}
<table class="franja" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" aria-hidden="true">
<tr>
<td bgcolor="#adcd60" height="6" style="height:6px;line-height:6px;font-size:0;">&nbsp;</td>
<td bgcolor="#355a63" height="6" style="height:6px;line-height:6px;font-size:0;">&nbsp;</td>
<td bgcolor="#eab02c" height="6" style="height:6px;line-height:6px;font-size:0;">&nbsp;</td>
<td bgcolor="#c76421" height="6" style="height:6px;line-height:6px;font-size:0;">&nbsp;</td>
<td bgcolor="#7ccbe1" height="6" style="height:6px;line-height:6px;font-size:0;">&nbsp;</td>
<td bgcolor="#ca3048" height="6" style="height:6px;line-height:6px;font-size:0;">&nbsp;</td>
<td bgcolor="#9c9b9b" height="6" style="height:6px;line-height:6px;font-size:0;">&nbsp;</td>
</tr>
</table>
<a href="{{ $url }}" style="display:inline-block;">
{{-- El escudo puede no cargarse: Gmail, Outlook y Apple Mail bloquean imágenes
     remotas por omisión. Debajo va el nombre en texto, así que el correo sigue
     siendo identificable sin la imagen. --}}
<img src="{{ rtrim(config('app.url'), '/') }}/vendor/muni-ui/logo-graneros.png"
     class="logo" alt="Escudo de la Municipalidad de Graneros">
</a>
<div class="header-nombre">{{ config('app.name') }}</div>
</td>
</tr>
```

`role="presentation"` y `aria-hidden="true"` en la franja: es decoración y no aporta ninguna información que no esté en el texto.

- [ ] **Step 4: Aplicar la paleta y el modo oscuro al tema**

En `resources/views/mail/html/themes/graneros.css`, sustituir los valores antiguos por los institucionales en todas sus apariciones:

- `#1a3a5f` → `#00404c` (títulos y enlaces de cabecera)
- `#4a5568` → `#355a63` (cuerpo)
- `#0d9488` → `#355a63` (fondo de botón, en `.button-blue` y `.button-primary`)
- `#8a94a6` → `#5d6b6f` (texto del pie)

Añadir al final del archivo:

```css
/* La franja ocupa todo el ancho y sus celdas no deben separarse. */
.franja {
    border-collapse: collapse;
    width: 100%;
}

.header-nombre {
    color: #00404c;
    font-size: 15px;
    font-weight: bold;
    margin-top: 10px;
}

/* Modo oscuro para los clientes que lo respetan: Apple Mail y Outlook moderno.
   Gmail no lo respeta e invierte los colores por su cuenta, así que ningún color
   del tema cae en la franja media donde esa inversión produce texto casi blanco
   sobre fondo casi blanco. */
@media (prefers-color-scheme: dark) {
    .body,
    .wrapper {
        background-color: #0f1a1d !important;
    }

    .content-cell,
    .inner-body {
        background-color: #16262a !important;
        color: #e8eef0 !important;
    }

    h1, h2, h3 {
        color: #e8eef0 !important;
    }

    p, li, td {
        color: #cdd9dc !important;
    }

    .panel-content {
        background-color: #1d3136 !important;
        color: #e8eef0 !important;
    }

    .header-nombre {
        color: #e8eef0 !important;
    }

    .footer p {
        color: #9fb0b4 !important;
    }

    /* La franja es lo que identifica al municipio y lo que más sufre la
       inversión automática: se declara para que ningún cliente la reinterprete. */
    .franja td {
        font-size: 0 !important;
        line-height: 6px !important;
    }
}
```

- [ ] **Step 5: Correr las pruebas y verlas pasar**

Run: `vendor/bin/phpunit --filter=TemaTest`
Expected: todos los casos PASAN.

- [ ] **Step 6: Confirmar que las pruebas detectan el defecto**

Devolver `#0d9488` al fondo del botón, correr las pruebas y comprobar que fallan las de contraste y la de colores anteriores. Restaurar `#355a63`.

- [ ] **Step 7: Commit**

```bash
git add resources/views/mail tests/
git commit -m "feat(correo): la identidad municipal, y dos contrastes que no pasaban AA

La cabecera lleva la franja de siete colores en celdas de tabla, visible aunque
el cliente bloquee imágenes. El botón daba 3.74:1 y el pie 3.06:1: los dos
estaban por debajo del 4.5:1 de WCAG AA, y los colores institucionales además
de correctos contrastan mejor."
```

---

### Task 3: Las tres vistas de autenticación, con respaldo

**Files:**
- Create: `resources/views/emails/auth/mfa.blade.php`, `bienvenida.blade.php`, `restablecer-contrasena.blade.php`
- Modify: `src/MuniSharedServiceProvider.php` (`boot()`), `src/SystemNotification.php`
- Test: `tests/Correo/VistasAuthTest.php`

**Interfaces:**
- Consumes: el tema de las tareas 1 y 2.
- Produces: el espacio de nombres de vistas `muni-mail-emails::`, y `SystemNotification::correo()` con resolución por respaldo. La Tarea 4 depende de que el respaldo funcione.

- [ ] **Step 1: Copiar las vistas**

```bash
mkdir -p resources/views/emails/auth
cp ../feria-graneros/resources/views/emails/auth/mfa.blade.php resources/views/emails/auth/
cp ../feria-graneros/resources/views/emails/auth/bienvenida.blade.php resources/views/emails/auth/
cp ../feria-graneros/resources/views/emails/auth/restablecer-contrasena.blade.php resources/views/emails/auth/
```

Se toma la variante de feria y discapacidad, que es la que ya convive con el tema institucional. La otra variante, en los cinco repositorios restantes, difiere solo en el `mfa` y no aporta nada que se pierda.

- [ ] **Step 2: Escribir la prueba que falla**

Crear `tests/Correo/VistasAuthTest.php`:

```php
<?php

namespace Muni\Shared\Tests\Correo;

use Muni\Shared\MfaCodeNotification;
use Muni\Shared\Tests\TestCase;

class VistasAuthTest extends TestCase
{
    public function test_las_tres_vistas_estan_en_el_paquete(): void
    {
        foreach (['mfa', 'bienvenida', 'restablecer-contrasena'] as $vista) {
            $this->assertTrue(
                view()->exists("muni-mail-emails::auth.{$vista}"),
                "Falta la vista {$vista} en el paquete"
            );
        }
    }

    public function test_el_correo_del_codigo_se_renderiza(): void
    {
        $notificacion = new MfaCodeNotification('482915', 10);

        $usuario = new class
        {
            public string $name = 'Funcionaria de Prueba';
            public string $email = 'prueba@municipalidadgraneros.cl';
        };

        $html = (string) $notificacion->toMail($usuario)->render();

        $this->assertStringContainsString('482915', $html);
        $this->assertStringContainsString('#adcd60', $html, 'El correo salió sin la franja institucional');
    }

    public function test_la_vista_del_repositorio_gana_sobre_la_del_paquete(): void
    {
        $propia = resource_path('views/emails/auth/mfa.blade.php');

        @mkdir(dirname($propia), 0777, true);
        file_put_contents($propia, 'VISTA PROPIA DEL SISTEMA');

        try {
            $notificacion = new MfaCodeNotification('000000', 10);

            $usuario = new class
            {
                public string $name = 'Prueba';
                public string $email = 'prueba@municipalidadgraneros.cl';
            };

            $html = (string) $notificacion->toMail($usuario)->render();

            $this->assertStringContainsString('VISTA PROPIA DEL SISTEMA', $html);
        } finally {
            @unlink($propia);
        }
    }
}
```

El tercer caso es el que importa para no romper nada: un sistema con vista propia debe conservarla al actualizar el paquete.

- [ ] **Step 3: Correr la prueba para verla fallar**

Run: `vendor/bin/phpunit --filter=VistasAuthTest`
Expected: FALLA — el espacio de nombres `muni-mail-emails::` no existe todavía.

- [ ] **Step 4: Registrar el espacio de nombres**

En `boot()` de `src/MuniSharedServiceProvider.php`, antes del bloque de consola:

```php
        // Las vistas de correo del paquete, bajo su propio espacio de nombres.
        // Se cargan y no se publican: quien quiera desviarse crea la suya en
        // resources/views/emails/auth y SystemNotification la prefiere.
        $this->loadViewsFrom(__DIR__.'/../resources/views/emails', 'muni-mail-emails');
```

Registrar también su publicación, junto a la del tema:

```php
            $this->publishes([
                __DIR__.'/../resources/views/emails' => resource_path('views/emails'),
            ], 'muni-mail-views');
```

- [ ] **Step 5: Resolver con respaldo en SystemNotification**

En `src/SystemNotification.php`, sustituir el método `correo()` y corregir el docblock de la clase, que hoy afirma algo que no era cierto:

```php
    /**
     * Construye un MailMessage con la vista Markdown indicada.
     *
     * Prefiere la vista del propio sistema y cae a la del paquete si no existe:
     * así, adoptar las compartidas es un acto deliberado —borrar la propia— y no
     * un efecto de actualizar el paquete.
     *
     * @param  array<string, mixed>  $data
     */
    protected function correo(string $asunto, string $vista, array $data = []): MailMessage
    {
        $delPaquete = 'muni-mail-emails::'.str_replace('emails.', '', $vista);

        $elegida = view()->exists($vista) ? $vista : $delPaquete;

        return (new MailMessage)
            ->subject($asunto)
            ->markdown($elegida, $data);
    }
```

Y en el docblock de la clase, sustituir la frase que dice que el tema lo aporta el paquete por una que describa lo que ahora sí ocurre:

```php
 * Las subclases solo aportan el asunto y la vista Markdown con el contenido. El
 * logo, los colores y el pie salen del tema «graneros», que este mismo paquete
 * registra en mail.markdown.paths (ver MuniSharedServiceProvider::register).
```

- [ ] **Step 6: Correr la prueba y verla pasar**

Run: `vendor/bin/phpunit --filter=VistasAuthTest`
Expected: los tres casos PASAN.

- [ ] **Step 7: Correr la suite completa del paquete**

Run: `vendor/bin/phpunit`
Expected: verde. Hay pruebas previas de `SystemNotification` y del transporte Graph que no deben romperse.

- [ ] **Step 8: Commit**

```bash
git add resources/views/emails src/ tests/
git commit -m "feat(correo): las tres vistas de autenticación viven en el paquete

Eran veintiún archivos en siete repositorios que en realidad son cuatro
contenidos: bienvenida y restablecer son idénticas byte a byte. La vista propia
de cada sistema sigue ganando, así que actualizar no le cambia el correo a
nadie sin querer."
```

---

### Task 4: Instalar y verificar en web-graneros

**Files:**
- Modify: `web-graneros/config/mail.php` (línea del tema)
- Modify: `web-graneros/composer.json` / `composer.lock`
- Delete: `web-graneros/resources/views/emails/auth/{mfa,bienvenida,restablecer-contrasena}.blade.php`

**Interfaces:**
- Consumes: todo lo anterior, ya commiteado y etiquetado.
- Produces: la evidencia de que el camino completo funciona. Las tareas 5 a 7 repiten este procedimiento en los demás sistemas.

- [ ] **Step 1: Etiquetar el paquete**

En `laravel-muni-shared`:

```bash
vendor/bin/phpunit
git tag -a v1.3.0 -m "Tema de correo institucional y vistas de autenticación compartidas"
```

Ajustar el número a la versión que corresponda: `git tag --list | tail -3` para ver la última.

- [ ] **Step 2: Actualizar el paquete en web-graneros**

```bash
docker exec web-graneros-app-frankenphp composer update muni-graneros/laravel-muni-shared
```

- [ ] **Step 3: Cambiar el tema**

`config/mail.php` declara `'theme' => 'default'` de forma explícita, y por eso el valor por omisión del paquete no se le aplica. Cambiar esa línea:

```php
            'theme' => env('MAIL_THEME', 'graneros'),
```

- [ ] **Step 4: Capturar cómo se ven los correos AHORA, antes de borrar nada**

Los tres correos, renderizados a archivo:

```bash
docker exec web-graneros-app-frankenphp php artisan tinker --execute="
foreach (['mfa' => ['codigo' => '482915', 'minutos' => 10, 'nombre' => 'Prueba'], 'bienvenida' => [], 'restablecer-contrasena' => []] as \$v => \$data) {
    file_put_contents('/tmp/antes-'.\$v.'.html', view('emails.auth.'.\$v, \$data)->render());
}
echo 'listo';"
docker cp web-graneros-app-frankenphp:/tmp/. ./capturas-correo/antes/
```

Si alguna vista pide variables que no están arriba, leerla y añadirlas: `cat resources/views/emails/auth/bienvenida.blade.php`.

- [ ] **Step 5: Borrar las vistas locales para que hereden las del paquete**

```bash
rm resources/views/emails/auth/mfa.blade.php \
   resources/views/emails/auth/bienvenida.blade.php \
   resources/views/emails/auth/restablecer-contrasena.blade.php
docker exec web-graneros-app-frankenphp php artisan view:clear
docker exec web-graneros-app-frankenphp php artisan config:clear
```

Este paso es el que hace que «se actualicen las notificaciones»: mientras las vistas locales existan, ganan sobre las del paquete y no cambia nada.

- [ ] **Step 6: Comprobar que las notificaciones siguen saliendo**

```bash
docker exec web-graneros-app-frankenphp php artisan test --filter=Mfa
```
Expected: verde. Si algún test montaba la vista local, aquí aparece.

- [ ] **Step 7: Capturar el después, en los cuatro estados**

Abrir cada HTML renderizado en el navegador y capturar **modo claro y modo oscuro**, **escritorio y móvil**: cuatro capturas por correo, doce en total. En Chrome DevTools, el modo oscuro se fuerza desde «Rendering → Emulate CSS prefers-color-scheme».

Comprobar en cada captura:
- La franja de siete colores se ve completa y en el orden correcto.
- El texto del botón se lee sobre su fondo.
- El pie se lee.
- Con las imágenes bloqueadas, la cabecera sigue identificando al municipio.

- [ ] **Step 8: Envío real**

```bash
docker exec web-graneros-app-frankenphp php artisan correo:probar --a=cbm3lla@gmail.com
```

En local, `APP_URL` apunta a localhost, así que **el escudo no cargará**: es el escenario de imagen bloqueada y sirve como prueba de que la cabecera aguanta sin él. Abrir el correo en Gmail —en el móvil también— y confirmar que la franja se ve y el texto se lee.

Si el correo cae en no deseados, no es este trabajo: el dominio publica un DMARC con la errata `p=quarentine`, que anula la política.

- [ ] **Step 9: Commit**

```bash
git add config/mail.php composer.json composer.lock
git rm resources/views/emails/auth/mfa.blade.php \
       resources/views/emails/auth/bienvenida.blade.php \
       resources/views/emails/auth/restablecer-contrasena.blade.php
git commit -m "feat(correo): adopta el tema institucional del paquete

Las tres vistas eran idénticas a las del resto del ecosistema: se borran para
heredar las compartidas. El tema estaba fijado a «default» de forma explícita,
así que había que cambiarlo a mano."
```

---

### Task 5: Adopción en los cinco sistemas sin bloque markdown

**Files:**
- Modify, en cada uno de `licencias-graneros`, `credenciales-graneros`, `seguridad-graneros`, `control-acceso-graneros`, `scaffold-laravel-filament-pwa`: `config/mail.php`, `composer.json`, `composer.lock`
- Delete, en cada uno: las tres vistas de `resources/views/emails/auth/` que existan

**Interfaces:**
- Consumes: el paquete etiquetado en la Tarea 4.
- Produces: nada que otras tareas consuman.

Estos cinco no declaran bloque `markdown` en `config/mail.php`, así que **adoptan el tema con solo actualizar el paquete**: no hay línea que cambiar. Es el caso más simple, y también el que más fácil se da por hecho sin comprobarlo.

Repetir para cada sistema, uno por uno, sin agrupar commits:

- [ ] **Step 1: Actualizar el paquete**

```bash
docker exec <contenedor> composer update muni-graneros/laravel-muni-shared
```

El nombre del contenedor se obtiene con `docker ps --format '{{.Names}}' | grep <sistema>`.

- [ ] **Step 2: Comprobar que el tema quedó activo**

```bash
docker exec <contenedor> php artisan tinker --execute="echo config('mail.markdown.theme');"
```
Expected: `graneros`. Si sale `default` o vacío, el repositorio sí tenía el bloque y hay que cambiarlo a mano como en la Tarea 4, Paso 3.

- [ ] **Step 3: Capturar el antes**

Mismo procedimiento de la Tarea 4, Paso 4.

- [ ] **Step 4: Borrar las vistas locales**

```bash
rm -f resources/views/emails/auth/mfa.blade.php \
      resources/views/emails/auth/bienvenida.blade.php \
      resources/views/emails/auth/restablecer-contrasena.blade.php
docker exec <contenedor> php artisan view:clear
```

Si el sistema tiene además vistas propias de su dominio —discapacidad tiene ocho— **no se tocan**: solo las tres de autenticación.

- [ ] **Step 5: Correr la suite**

```bash
docker exec <contenedor> php artisan test
```
Expected: verde. Licencias corre 143 pruebas y PHPStan nivel 8 sin baseline: no se admite ninguna regresión.

- [ ] **Step 6: Envío real**

```bash
docker exec <contenedor> php artisan correo:probar --a=cbm3lla@gmail.com
```

Si el sistema no tiene credenciales de Graph en su `.env`, el comando lo dirá y no es un fallo de este trabajo: se anota y se sigue.

- [ ] **Step 7: Commit por sistema**

```bash
git add config/mail.php composer.json composer.lock
git rm -r --cached resources/views/emails/auth 2>/dev/null || true
git commit -m "feat(correo): adopta el tema institucional del paquete"
```

- [ ] **Step 8: Anotar el resultado**

Llevar una tabla con los cinco: si el tema quedó activo, si la suite pasó, si el envío salió. Un sistema que falle no bloquea a los otros, pero tiene que quedar dicho cuál fue.

---

### Task 6: Adopción en feria y discapacidad

**Files:**
- Delete, en cada uno: `resources/views/vendor/mail/` completo (18 archivos)
- Delete, en cada uno: las tres vistas de `resources/views/emails/auth/`
- Modify, en cada uno: `composer.json`, `composer.lock`

**Interfaces:**
- Consumes: el paquete etiquetado en la Tarea 4.
- Produces: nada que otras tareas consuman.

Son los dos únicos sistemas donde alguien puede notar el cambio de un día para otro: hoy usan el tema copiado, con los colores no institucionales y los dos contrastes que fallan.

- [ ] **Step 1: Capturar el antes, con más cuidado que en el resto**

Renderizar los correos como en la Tarea 4, Paso 4, y guardar las capturas. Aquí el antes y el después son visiblemente distintos, y hay que poder mostrar por qué el cambio es una mejora y no un capricho.

- [ ] **Step 2: Comprobar que las copias son las esperadas**

```bash
diff -r resources/views/vendor/mail ../feria-graneros/resources/views/vendor/mail
```

En discapacidad, la única diferencia debería ser una línea de comentario que nombra su panel. Si aparece cualquier otra, **detenerse**: alguien personalizó el tema y hay que mirar qué antes de borrarlo.

- [ ] **Step 3: Actualizar el paquete y borrar las copias**

```bash
docker exec <contenedor> composer update muni-graneros/laravel-muni-shared
git rm -r resources/views/vendor/mail
git rm resources/views/emails/auth/mfa.blade.php \
       resources/views/emails/auth/bienvenida.blade.php \
       resources/views/emails/auth/restablecer-contrasena.blade.php
docker exec <contenedor> php artisan view:clear
```

- [ ] **Step 4: Capturar el después y comparar**

Las cuatro combinaciones por correo, como en la Tarea 4, Paso 7. Poner las capturas de antes y después una al lado de la otra y comprobar que el botón y el pie ahora se leen.

- [ ] **Step 5: Correr la suite**

```bash
docker exec <contenedor> php artisan test
```
Expected: verde.

- [ ] **Step 6: Envío real y commit**

```bash
docker exec <contenedor> php artisan correo:probar --a=cbm3lla@gmail.com
git commit -m "feat(correo): usa el tema institucional del paquete en vez de su copia

Las dieciocho vistas eran una copia idéntica a la de otro repositorio, salvo un
comentario. Desde el paquete llegan además los dos contrastes corregidos."
```

---

### Task 7: Traspaso

**Files:**
- Create: `docs/superpowers/plans/2026-08-18-tema-correo-institucional-resultado.md`

**Interfaces:**
- Consumes: las tareas 1 a 6.
- Produces: el registro de qué quedó hecho y qué no.

- [ ] **Step 1: Escribir el resultado**

Una tabla con los nueve sistemas: versión del paquete, tema activo, vistas borradas, suite verde, envío probado. Y una lista de lo que quedó pendiente, con el motivo.

- [ ] **Step 2: Anotar lo que este trabajo NO arregla**

- El DMARC del dominio: `p=quarentine` con errata, que anula la política. Un correo puede verse perfecto y caer en no deseados.
- Los sistemas sin credenciales de Graph en su `.env`, que no pudieron probar el envío real.
- `plataforma-graneros`, que no tiene vistas de correo y por tanto no necesita adopción.

- [ ] **Step 3: Commit**

```bash
git add docs/superpowers/plans/2026-08-18-tema-correo-institucional-resultado.md
git commit -m "docs: cómo quedó la adopción del tema de correo en el ecosistema"
```

---

## Self-review

**Cobertura del spec.** Arquitectura y distribución → Tarea 1. Cabecera, paleta y modo oscuro → Tarea 2. Vistas de autenticación y resolución con respaldo → Tarea 3. Pruebas del paquete → tareas 1 a 3. Verificación en web-graneros → Tarea 4. Adopción en los ocho restantes → tareas 5 y 6. Riesgos → Tarea 7.

**Sin marcadores de posición.** Todos los pasos de código llevan el código. Donde hay que leer un archivo antes de escribir algo —las variables que pide una vista— el paso lo dice y da el comando.

**Consistencia de nombres.** El espacio de nombres de vistas es `muni-mail-emails::` en el `loadViewsFrom` de la Tarea 3 y en `SystemNotification::correo()`. La etiqueta de publicación es `muni-mail-views` en las tareas 1 y 3, para el tema y para las vistas: una sola etiqueta publica ambos. La prueba de la Tarea 3 comprueba `view()->exists('muni-mail-emails::auth.{vista}')`, que coincide con el `loadViewsFrom(__DIR__.'/../resources/views/emails', 'muni-mail-emails')` del Paso 4: la raíz del espacio de nombres es `resources/views/emails`, así que dentro de él la vista es `auth.mfa` y no `emails.auth.mfa`.
