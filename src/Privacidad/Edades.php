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
     * El cálculo usa la zona horaria de la aplicación, que es la del municipio
     * que opera el registro: la frontera se cruza el día del cumpleaños allí.
     */
    public function esNNA(TitularDeDatos $titular): ?bool
    {
        $nacimiento = $titular->fechaNacimientoTitular();

        if ($nacimiento === null) {
            return null;
        }

        return Carbon::instance($nacimiento)->age < self::MAYORIA_DE_EDAD;
    }
}
