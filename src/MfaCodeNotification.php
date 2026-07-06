<?php

namespace Muni\Shared;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Entrega el código de verificación en dos pasos (MFA) por correo.
 *
 * NO se encola (no implementa ShouldQueue): el código es sensible a la latencia
 * y debe salir en el mismo request. Con MAIL_MAILER=log queda escrito en el log,
 * igual que el comportamiento histórico de VerificarMfa.
 */
class MfaCodeNotification extends SystemNotification
{
    public function __construct(
        private readonly string $codigo,
        private readonly int $minutosVigencia = 10,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->correo(
            asunto: 'Tu código de verificación · '.config('app.name'),
            vista: 'emails.auth.mfa',
            data: [
                'codigo' => $this->codigo,
                'minutos' => $this->minutosVigencia,
                'nombre' => $notifiable->name ?? null,
            ],
        );
    }
}
