<?php

namespace Muni\Shared\Privacidad;

enum MedioDeConsentimiento: string
{
    case FirmaPapel = 'firma_papel';
    case FirmaDigital = 'firma_digital';
    case VerbalRegistrada = 'verbal_registrada';
}
