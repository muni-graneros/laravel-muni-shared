# Correo institucional único para los sistemas municipales — diseño

Fecha: 2026-11-03. Alcance pedido por César: **solo sistemas municipales** (Hefesto/KraftDo queda fuera).
Logo: `~/Dev/Logos municipalidad de Graneros-10.png` (logo horizontal, escudo + "MUNICIPALIDAD DE GRANEROS").

## Problema

12 sistemas municipales mandan correo y cada uno se ve distinto:

- 6 usan la plantilla por defecto de Laravel (cabecera con el nombre de la app, botón negro).
- 6 tienen `resources/views/vendor/mail` propio, cada uno con logo, color y pie distintos
  (#0D5963, #355a63, #0d9488, #0a4952; escudo por URL, logo adjunto, sin logo).
- `SystemNotification` (este paquete) documenta un tema "graneros" que solo existe copiado en 2 repos.
- Los logos por URL (`APP_URL/vendor/muni-ui/...`) no se ven fuera de la VPN: varios sistemas son internos.

## Decisión

La plantilla vive en **`laravel-muni-shared`**, la única dependencia que comparten los 12 (atencionvecino no usa
muni-ui). El paquete registra sus componentes `mail::` **por delante** de los de la app y fija el tema `graneros`, así
que actualizar el paquete basta para que todos los correos (Notifications con `->line()`, `->markdown()`,
`SystemNotification`, Mailables Markdown, el código MFA de laravel-muni-mfa) salgan iguales.

- **Logo incrustado (CID), nunca por URL**: la plantilla escribe `cid:logo-municipalidad-graneros@muni-graneros` y un
  listener de `MessageSending` adjunta el PNG inline solo si el HTML lo referencia. Se ve en Gmail/Outlook aunque el
  sistema esté detrás de la VPN. PNG de 480×134 sobre blanco opaco (se lee igual con modo oscuro del cliente).
- **Cabecera**: logo, nombre del sistema debajo, franja de 7 colores institucionales (tabla, no degradado).
- **Color**: petróleo institucional `#355a63` en botones y enlaces (contraste 7.5:1 con blanco, AA).
- **Pie**: unidad opcional · "Ilustre Municipalidad de Graneros", enlace al sitio y aviso de correo automático.
- **Config** `correo-institucional.php`: `sistema` (`MUNI_CORREO_SISTEMA`, por defecto `APP_NAME`), `unidad`
  (`MUNI_CORREO_UNIDAD`), `sitio`.

## Fases

1. **Paquete** (este plan): plantilla + logo + listener + tests + CHANGELOG. Release v1.24.0 requiere push/tag a
   `muni-graneros` → autorización explícita de César (los repos municipales no se publican sin ella).
2. **Adopción por sistema** (plan aparte por repo, tras el tag): subir el paquete, borrar `resources/views/vendor/mail`,
   quitar `'theme' => 'graneros'` local, fijar `MUNI_CORREO_SISTEMA`/`MUNI_CORREO_UNIDAD` en `.env.example`, ajustar el
   test de marca de seguridad (`CorreosConMarcaMunicipalTest`, que hoy espera la URL del escudo) y añadir un test de
   render por sistema. control-acceso `ReporteProgramado` usa `->html()` crudo: pasarlo a Markdown.
