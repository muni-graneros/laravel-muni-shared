<?php

namespace Muni\Shared\Privacidad;

/**
 * Quién actúa: el propio titular o alguien por él.
 *
 * Un enum y no un string libre, por dos razones. La primera es de derecho: el
 * ejercicio de un derecho ARCOP por un tercero hay que acreditarlo, y una
 * columna que acepta cualquier texto no obliga a nadie a decidir qué es ese
 * tercero. La segunda es de privacidad, y es la que hace que esto sea código de
 * este módulo y no cosmética: el barrido de anonimización CONSERVA
 * `privacidad_solicitudes.solicitante` y `privacidad_consentimientos.otorgado_por`
 * porque son categorías y no identifican a nadie. Mientras fueran strings
 * libres, ese argumento dependía de que ningún sistema adoptante escribiera ahí
 * «Juan Pérez, hijo»; con un enum, la columna no puede contener otra cosa que
 * una de estas etiquetas, y el argumento pasa de esperanza a garantía.
 *
 * El mismo enum sirve a las dos columnas porque la pregunta es la misma —¿actúa
 * el titular o alguien en su representación?— y tener dos listas paralelas que
 * decir lo mismo termina en que solo una se mantiene.
 *
 * Tampoco existe un caso «tutor», y esto hay que decirlo porque el spec de
 * diseño y el plan del ciclo 2-b lo daban por existente media docena de veces
 * («`otorgado_por = 'tutor'`» para el consentimiento de un NNA): con la columna
 * casteada a este enum, ese string reventaría con `ValueError` al crear la fila.
 * Quien consiente por un menor va como `RepresentanteLegal` —el tutor o curador
 * de un NNA ES su representante legal, y `Apoderado` no sirve porque un menor no
 * puede otorgar mandato—. Agregar el caso sería tener dos etiquetas para el
 * mismo rol jurídico en una columna que el barrido conserva justamente por ser
 * categórica y unívoca.
 *
 * NO existe un caso «heredero», y la ausencia es deliberada: el módulo todavía
 * no modela el fallecimiento del titular (qué derechos se transmiten, con qué
 * acreditación, por cuánto tiempo). Ofrecer la etiqueta sin esas reglas dejaría
 * registrar solicitudes de herederos que después nadie sabe cómo tramitar.
 */
enum Solicitante: string
{
    case Titular = 'titular';
    case RepresentanteLegal = 'representante_legal';
    case Apoderado = 'apoderado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Titular => 'El propio titular',
            self::RepresentanteLegal => 'Representante legal',
            self::Apoderado => 'Apoderado con mandato',
        };
    }

    /**
     * Si actúa un tercero, el sistema tiene que guardar con qué documento
     * acreditó la representación; el titular solo acredita su identidad.
     */
    public function exigeAcreditarRepresentacion(): bool
    {
        return $this !== self::Titular;
    }
}
