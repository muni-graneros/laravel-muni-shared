# Changelog

Los cambios que le importan a quien instala este paquete. Los 11 sistemas
municipales dependen de él, así que acá se anota **qué se rompe al subir** y qué
hay que hacer después de actualizar, no solo qué se agregó.

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado: [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

### Añadido
- `AssertEnvExampleCompleto` y `ContratoDeEnvExample`: el paquete trae su propio
  candado para que un `.env.example` no se desalinee de lo que `config()` lee.
  Los sistemas lo usan con un test de una línea.
- PHPStan nivel 8 y `composer audit` bloqueante en CI; Dependabot semanal contra
  `develop` (nunca contra `main`: el flujo del ecosistema es develop → main y
  una PR contra `main` se lo saltaría).

### Corregido
- El texto libre del módulo de Privacidad queda **cifrado en reposo** (Ley
  21.719). Requiere correr `php artisan privacidad:cifrar-texto-libre` una vez
  después de migrar; la migración crea las columnas, el comando traslada lo que
  ya estaba escrito en claro.
- `Bloqueos::vigente()` comparaba `titular_id` (varchar) contra un entero de PHP,
  así que MariaDB descartaba el índice del morph y escaneaba la tabla entera.
- El RUT y la dirección del vecino dejaban de estar en claro en los registros y
  en GlitchTip: el mensaje de excepción de Guzzle incluye la URI completa, y la
  URI llevaba el RUT.

## [1.17.1] - 2026-09-02
- El acceso federado deja de emitir la cookie de «Recordarme»: con SSO, esa
  cookie permite volver a entrar sin pasar por el proveedor de identidad.

## [1.17.0] - 2026-09-01
- `Geocoder` distingue «no pude preguntar» de «no existe» y deja de cachear los
  fallos, que envenenaban la caché durante horas ante una caída momentánea.

## [1.16.0] - 2026-08-25
- Módulo Privacidad: si procede entregar la copia, deja de ser una regla
  escondida en el motor del ciclo.

---

Antes de la 1.16.0 no se llevó changelog. El historial de `git log` es la
referencia para esas versiones.
