<?php

namespace Muni\Shared\Privacidad\Contratos;

use Illuminate\Database\Eloquent\Model;

/**
 * Cómo encuentra el sistema adoptante a la persona que viene al mesón.
 *
 * Es el punto de extensión que ningún panel puede resolver por su cuenta:
 * discapacidad busca por RUT contra la columna generada `nro_documento_norm` de
 * `personas`, otro sistema buscará por padrón, por número de licencia o por
 * correo. Adivinar el esquema del adoptante es exactamente lo que hacía que el
 * ciclo ARCOP viviera en un solo repo.
 *
 * Vive en el módulo y no en un paquete de panel porque los dos paneles del
 * ecosistema —el de Filament y el de Blade— tienen que buscar igual: si buscan
 * distinto, el mismo vecino recibe respuestas distintas según qué mesón lo
 * atendió.
 *
 * Las dos operaciones son distintas a propósito: el formulario busca sobre lo
 * que el funcionario tipea, y la recepción resuelve la clave elegida en un
 * titular de verdad. Un solo método obligaría a volver a buscar por texto para
 * recuperar a alguien que ya se había elegido.
 *
 * Quien lo implemente tiene dos obligaciones que el módulo no puede imponer por
 * código: exigir un mínimo de caracteres y acotar los resultados. Este buscador
 * es la superficie por donde se puede ENUMERAR el padrón de un municipio.
 */
interface BuscaTitulares
{
    /**
     * Titulares que calzan con lo tipeado, como opciones del selector.
     *
     * @return array<int|string, string> clave del titular => etiqueta visible
     */
    public function buscar(string $termino): array;

    /**
     * El titular con esa clave, o `null` si ya no está.
     *
     * El tipo de retorno exige las dos caras a la vez —modelo Eloquent y
     * `TitularDeDatos`— porque el módulo de privacidad necesita las dos: el
     * morph guarda la clave y el tipo, y el ciclo ARCOP conversa con el
     * contrato.
     */
    public function encontrar(int|string $clave): (Model&TitularDeDatos)|null;
}
