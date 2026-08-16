<?php

namespace Muni\Shared\Privacidad;

use RuntimeException;

/**
 * `privacidad.disco_evidencia` está en blanco o nombra un disco que
 * `filesystems.disks` no tiene configurado.
 *
 * Es RuntimeException y no DomainException por el mismo motivo que
 * ArchivoNoSuprimido: no hay nada inválido en lo que se pidió, es el entorno
 * —acá, la configuración del sistema adoptante— el que no está listo.
 *
 * Se lanza en vez de resolver contra el disco por defecto de Laravel, que es
 * lo que hacía antes sin que nadie se enterara: `Storage::disk('')` cae en
 * `getDefaultDriver()` en silencio, así que un valor en blanco no truena, se
 * confunde con el disco correcto y el barrido borra donde no debe (o donde no
 * hay nada). Fallar acá, antes de tocar el disco, es lo que impide que la
 * anonimización cuente un documento como suprimido sin haberlo tocado.
 */
class DiscoEvidenciaNoConfigurado extends RuntimeException {}
