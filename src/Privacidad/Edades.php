<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Support\Carbon;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;

/**
 * Dónde está la frontera de los 18 años, en un solo lugar.
 *
 * Vive en el módulo y no en cada sistema porque es una regla legal, no un
 * detalle de esquema: ocho implementaciones de "¿es menor?" son ocho lugares
 * donde el off-by-one del día del cumpleaños puede aparecer por separado.
 */
class Edades
{
    /** Mayoría de edad en Chile (art. 26 del Código Civil). */
    public const MAYORIA_DE_EDAD = 18;

    /**
     * ¿Es el titular niño, niña o adolescente?
     *
     * Devuelve `null` cuando el sistema no tiene acreditada la fecha de
     * nacimiento. `null` es un tercer estado y no un "no": quien consuma esto
     * tiene que decidir qué hace con la edad desconocida, y el módulo no elige
     * por él la respuesta cómoda. Es el mismo criterio de `Brecha::riesgo_alto`.
     *
     * Qué se compara con qué, porque acá antes decía «usa la zona horaria de la
     * aplicación» y era falso: `Carbon::instance($fecha)->age` compara contra el
     * «ahora» de LA ZONA DEL VALOR QUE DEVOLVIÓ EL SISTEMA ADOPTANTE. Un
     * adoptante que hidrate la fecha desde el maestro federado como
     * `DateTimeImmutable` en UTC convertía a un menor en adulto durante las
     * últimas horas antes de su cumpleaños número 18 —en Santiago son las 21:00
     * del día anterior y en UTC ya es el día del cumpleaños—. La suite no lo
     * veía porque el fixture pasa por el cast de Eloquent, que devuelve siempre
     * la zona de la aplicación.
     *
     * Ahora se comparan dos FECHAS DE CALENDARIO:
     *
     * - la de nacimiento, leída tal como el valor la presenta —`format('Y-m-d')`
     *   en la zona que trae— y NO convertida con `setTimezone()`, que movería el
     *   día y empeoraría el problema en vez de arreglarlo: una fecha de
     *   nacimiento es un día del calendario, no un instante;
     * - la de hoy en `config('app.timezone')`, que es la del municipio que opera
     *   el registro.
     *
     * Lo que esto NO cubre, dicho en su medida: si el adoptante guarda la fecha
     * como un instante con hora en otra zona (un `2008-06-15 21:00` de Santiago
     * persistido como `2008-06-16 00:00` en UTC), la fecha de calendario que ve
     * este método ya viene corrida y el módulo no tiene con qué detectarlo. El
     * contrato pide una fecha de nacimiento; devolverla como fecha —el cast
     * `date` de Eloquent— es lo que la mantiene bien.
     */
    public function esNNA(TitularDeDatos $titular): ?bool
    {
        $nacimiento = $titular->fechaNacimientoTitular();

        if ($nacimiento === null) {
            return null;
        }

        $zona = (string) (config('app.timezone') ?: date_default_timezone_get());

        // El «!» pone en cero la hora: lo que se compara es el día, no el
        // instante en que el registro civil anotó el nacimiento.
        $cumpleMayoria = Carbon::createFromFormat('!Y-m-d', $nacimiento->format('Y-m-d'), $zona)
            ->addYears(self::MAYORIA_DE_EDAD);

        return Carbon::now($zona)->startOfDay()->lt($cumpleMayoria);
    }
}
