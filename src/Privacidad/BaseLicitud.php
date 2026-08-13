<?php

namespace Muni\Shared\Privacidad;

/**
 * Las bases de licitud del art. 12 de la Ley 21.719.
 *
 * Es un enum y no un string libre porque la base de licitud es lo primero que
 * pregunta una fiscalización, y un valor inventado equivale a no tener base.
 */
enum BaseLicitud: string
{
    case FuncionLegal = 'funcion_legal';
    case Consentimiento = 'consentimiento';
    case Contrato = 'contrato';
    case ObligacionLegal = 'obligacion_legal';
    case InteresVital = 'interes_vital';
    case InteresLegitimo = 'interes_legitimo';

    /**
     * Fundarse en la ley obliga a decir en cuál. Sin la cita, la base es una
     * declaración vacía que no resiste una revisión.
     */
    public function exigeNormaHabilitante(): bool
    {
        return $this === self::FuncionLegal || $this === self::ObligacionLegal;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::FuncionLegal => 'Ejercicio de funciones legales',
            self::Consentimiento => 'Consentimiento del titular',
            self::Contrato => 'Ejecución de un contrato',
            self::ObligacionLegal => 'Cumplimiento de una obligación legal',
            self::InteresVital => 'Interés vital del titular',
            self::InteresLegitimo => 'Interés legítimo del responsable',
        };
    }
}
