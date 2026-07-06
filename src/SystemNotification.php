<?php

namespace Muni\Shared;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Clase base de todas las notificaciones del sistema.
 *
 * Centraliza el canal por defecto (correo) y un constructor de MailMessage ya
 * "marcado" con el saludo y la firma institucional. Las subclases solo aportan
 * el asunto y la vista Markdown con el contenido — el logo, los colores y el
 * pie salen del tema "graneros" (config/mail.php → resources/views/vendor/mail).
 *
 * Para que una notificación se procese en segundo plano (cola Redis del worker)
 * basta con que la subclase implemente Illuminate\Contracts\Queue\ShouldQueue.
 * Las sensibles a la latencia (p. ej. el código MFA) NO lo implementan y se
 * envían de forma síncrona.
 */
abstract class SystemNotification extends Notification
{
    use Queueable;

    /**
     * Canales de entrega. Hoy solo correo; mañana se puede añadir 'database'
     * o un canal SMS sin tocar las subclases.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Construye un MailMessage con la vista Markdown indicada. La vista hereda
     * automáticamente el layout institucional (logo + paleta Graneros).
     *
     * @param  array<string, mixed>  $data
     */
    protected function correo(string $asunto, string $vista, array $data = []): MailMessage
    {
        return (new MailMessage)
            ->subject($asunto)
            ->markdown($vista, $data);
    }
}
