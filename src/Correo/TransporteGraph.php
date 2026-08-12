<?php

namespace Muni\Shared\Correo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Manda el correo por la API de Microsoft Graph en vez de por SMTP.
 *
 * Las casillas municipales tienen segundo factor, y SMTP con usuario y
 * contraseña no sabe hacerlo: ese camino lo bloquea el propio Microsoft. Acá el
 * sistema no entra como una persona sino como una aplicación registrada, con su
 * propio permiso, así que no hay contraseña de nadie de por medio.
 *
 * Se probaron los otros dos caminos y quedaron descartados:
 *
 *   - SMTP autenticado: lo bloquea el segundo factor de las casillas.
 *   - Entrega directa por el puerto 25: solo entrega dentro del propio dominio,
 *     y además su certificado no valida desde Linux — esa entrada usa la PKI
 *     propia de Microsoft y sirve una cadena que los almacenes no completan.
 *
 * Se manda el mensaje MIME tal cual lo armó Laravel, en vez de traducirlo al
 * formato JSON de Graph campo por campo. Traducirlo obligaría a acordarse de
 * cada cosa —adjuntos, copias ocultas, responder-a, cabeceras— y lo que se
 * olvide se pierde en silencio.
 */
final class TransporteGraph extends AbstractTransport
{
    /**
     * Graph acepta hasta 4 MB por esta vía. Un mensaje más grande hay que
     * subirlo por partes; los sistemas municipales no mandan nada parecido, así
     * que en vez de implementar eso se avisa claro si algún día pasa.
     */
    private const LIMITE_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly string $tenant,
        private readonly string $cliente,
        private readonly string $secreto,
        private readonly string $remitente,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $mime = $message->toString();

        if (strlen($mime) > self::LIMITE_BYTES) {
            throw new \RuntimeException(
                'El correo pesa más de 4 MB, que es el máximo que acepta Graph de una vez.',
            );
        }

        $respuesta = Http::withToken($this->permiso())
            // Graph acepta el mensaje MIME crudo cuando el cuerpo va como texto
            // plano en base64. Con JSON esperaría el formato campo por campo.
            ->withBody(base64_encode($mime), 'text/plain')
            ->timeout(20)
            ->post(sprintf(
                'https://graph.microsoft.com/v1.0/users/%s/sendMail',
                urlencode($this->remitente),
            ));

        if ($respuesta->failed()) {
            // El cuerpo del error de Graph trae el motivo real (permiso que
            // falta, remitente fuera de la política); el código HTTP solo no
            // alcanza para saber a quién reclamarle.
            throw new \RuntimeException(sprintf(
                'Microsoft rechazó el envío (HTTP %d): %s',
                $respuesta->status(),
                $this->motivo($respuesta->json()),
            ));
        }
    }

    /**
     * El permiso para mandar correo, pedido con las credenciales de la
     * aplicación.
     *
     * Es público para que `correo:probar` pueda comprobar el registro sin
     * tener que mandarle un correo a nadie.
     */
    public function permiso(): string
    {
        $guardado = $this->desdeLaCache();

        if ($guardado !== null) {
            return $guardado;
        }

        $token = $this->pedirPermiso();
        $this->guardarEnCache($token);

        return $token;
    }

    /**
     * El permiso guardado, o null si no hay o si la caché no responde.
     *
     * Los errores de la caché se ignoran a propósito: guardar el permiso es una
     * optimización, no un requisito. Que Redis esté caído no tiene por qué
     * cortar el correo, y sobre todo no tiene que parecer que Microsoft rechazó
     * a la aplicación — eso manda a reclamarle al que no es.
     */
    private function desdeLaCache(): ?string
    {
        try {
            $guardado = Cache::get('correo:graph:token');
        } catch (\Throwable) {
            return null;
        }

        return is_string($guardado) && $guardado !== '' ? $guardado : null;
    }

    private function guardarEnCache(string $token): void
    {
        try {
            // Menos de la hora que dura, para no usar uno que venza a mitad de
            // camino.
            Cache::put('correo:graph:token', $token, now()->addMinutes(50));
        } catch (\Throwable) {
            // Sin caché se pide uno por correo. Más lento, pero funciona.
        }
    }

    private function pedirPermiso(): string
    {
        $respuesta = Http::asForm()
            ->timeout(15)
            ->post(
                "https://login.microsoftonline.com/{$this->tenant}/oauth2/v2.0/token",
                [
                    'client_id' => $this->cliente,
                    'client_secret' => $this->secreto,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            );

        if ($respuesta->failed()) {
            // Sin el cuerpo de la respuesta: en los errores de autenticación
            // suele venir de vuelta parte de lo enviado, y este mensaje termina
            // en el log y en la tabla de trabajos fallidos.
            throw new \RuntimeException(
                'Microsoft no entregó el permiso para enviar correo (HTTP '
                .$respuesta->status().'). Revisar el registro de la aplicación.',
            );
        }

        $token = $respuesta->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Microsoft respondió sin el permiso de envío.');
        }

        return $token;
    }

    private function motivo(mixed $cuerpo): string
    {
        if (is_array($cuerpo) && isset($cuerpo['error']['message']) && is_string($cuerpo['error']['message'])) {
            return $cuerpo['error']['message'];
        }

        return 'sin detalle';
    }

    public function __toString(): string
    {
        return 'graph://'.$this->remitente;
    }
}
