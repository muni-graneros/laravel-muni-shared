<?php

namespace Muni\Shared\Privacidad;

enum MedioDeConsentimiento: string
{
    case FirmaPapel = 'firma_papel';
    case FirmaDigital = 'firma_digital';
    case VerbalRegistrada = 'verbal_registrada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FirmaPapel => 'Firma en papel',
            self::FirmaDigital => 'Firma digital',
            self::VerbalRegistrada => 'Verbal, registrada',
        };
    }
}
