<?php

namespace Muni\Shared\Correo;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Incrusta el logo institucional en el correo que se está por enviar.
 *
 * La plantilla escribe `cid:logo-municipalidad-graneros@muni-graneros` en el
 * `<img>`; acá va la parte MIME que le corresponde. Va incrustado y no por
 * URL a propósito: varios sistemas municipales solo se ven por VPN, y una
 * imagen remota no carga en el cliente del vecino — Gmail y Outlook la
 * bloquean.
 *
 * Solo adjunta si el correo referencia el logo: un `Mail::html()` crudo, o un
 * informe programado, no lo necesitan y no deben llevarlo encima.
 */
final class IncrustarLogoInstitucional
{
    /**
     * @param  string|null  $rutaLogo  PNG a incrustar. `null` → el del paquete.
     */
    public function __construct(private readonly ?string $rutaLogo = null) {}

    public function handle(MessageSending $evento): void
    {
        $email = $evento->message;

        $cuerpo = $email->getHtmlBody();

        if (! is_string($cuerpo) || ! str_contains($cuerpo, 'cid:'.PlantillaInstitucional::CID)) {
            return;
        }

        if ($this->yaVaIncrustado($email)) {
            return;
        }

        $ruta = $this->rutaLogo ?? PlantillaInstitucional::rutaLogo();

        // El correo sale igual, sin logo. Un logo que no se ve es un detalle;
        // un correo que no sale es un vecino sin el código de acceso.
        if (! is_file($ruta)) {
            Log::warning('El logo institucional no está en el paquete: el correo sale sin logo.');

            return;
        }

        try {
            $email->addPart(
                (new DataPart(new File($ruta), 'logo-municipalidad-graneros.png', 'image/png'))
                    ->asInline()
                    ->setContentId(PlantillaInstitucional::CID)
            );
        } catch (\Throwable $error) {
            // Sin datos del destinatario: este aviso se lee en un log que
            // circula, y el destinatario no tiene por qué andar en él.
            Log::warning('No se pudo incrustar el logo institucional en el correo: '.$error->getMessage());
        }
    }

    /**
     * Si el logo ya va adjunto con ese content-id.
     *
     * El listener es idempotente a propósito: se puede registrar dos veces, o
     * el mismo correo pasar por dos mailers, y el logo no debe repetirse.
     */
    private function yaVaIncrustado(Email $email): bool
    {
        foreach ($email->getAttachments() as $parte) {
            if ($parte->hasContentId() && $parte->getContentId() === PlantillaInstitucional::CID) {
                return true;
            }
        }

        return false;
    }
}
