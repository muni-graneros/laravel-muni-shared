<?php

namespace Muni\Shared\Correo;

use Carbon\CarbonImmutable;

/**
 * Lee la configuración de correo y dice si el sistema puede enviar de verdad.
 *
 * Vive aparte del comando que la usa porque la comprueban dos lugares —el
 * comando y la pantalla de salud de cada sistema— y las dos tienen que
 * responder lo mismo. Duplicadas, tarde o temprano dejan de coincidir y nadie
 * sabe a cuál creerle.
 */
final class ConfiguracionDeCorreo
{
    /**
     * Cómo está configurado el envío ahora mismo.
     *
     * Se deduce de la propia configuración y no de una variable aparte: una
     * variable aparte se desincroniza del resto y termina mintiendo.
     */
    public static function modoDeEnvio(string $mailer, ?string $usuario = null): string
    {
        if (in_array($mailer, ['log', 'array'], true)) {
            return 'sin-envio';
        }

        if ($mailer === 'graph') {
            return 'graph';
        }

        return ($usuario === null || $usuario === '')
            ? 'entrega-directa'
            : 'autenticado';
    }

    public static function modoActual(): string
    {
        $usuario = config('mail.mailers.smtp.username');

        return self::modoDeEnvio(
            (string) config('mail.default'),
            is_string($usuario) ? $usuario : null,
        );
    }

    /**
     * Las credenciales que faltan para poder enviar por Graph.
     *
     * @return array<int, string>
     */
    public static function credencialesQueFaltan(): array
    {
        $requeridas = [
            'MICROSOFT_GRAPH_TENANT_ID' => 'tenant',
            'MICROSOFT_GRAPH_CLIENT_ID' => 'cliente',
            'MICROSOFT_GRAPH_CLIENT_SECRET' => 'secreto',
            'MICROSOFT_GRAPH_REMITENTE' => 'remitente',
        ];

        $faltan = [];

        foreach ($requeridas as $variable => $clave) {
            $valor = config("mail.mailers.graph.{$clave}");

            if (! is_string($valor) || trim($valor) === '') {
                $faltan[] = $variable;
            }
        }

        return $faltan;
    }

    /**
     * Lo que impediría que un correo llegue a destino.
     *
     * @return array<int, string>
     */
    public static function problemas(string $modo, string $remitente): array
    {
        if ($modo !== 'graph') {
            return [];
        }

        $casilla = config('mail.mailers.graph.remitente');

        // El correo sale de la casilla que autoriza la política de acceso del
        // registro. Si el remitente configurado es otro, Microsoft rechaza el
        // envío o lo reescribe, y en los dos casos no es lo que se esperaba.
        if (is_string($casilla) && $casilla !== '' && strtolower($casilla) !== strtolower($remitente)) {
            return ["El remitente ({$remitente}) no coincide con la casilla autorizada "
                ."en el registro de la aplicación ({$casilla})."];
        }

        return [];
    }

    /**
     * Cuántos días le quedan al secreto de la aplicación.
     *
     * Negativo si ya venció, y null si nadie anotó la fecha o si lo anotado no
     * se entiende. Los dos casos de null son igual de graves: sin fecha no hay
     * aviso posible y el sistema se cierra el día que venza sin que nadie lo
     * viera venir.
     */
    public static function diasHastaElVencimiento(): ?int
    {
        $vence = config('mail.mailers.graph.vence');

        if (! is_string($vence) || $vence === '') {
            return null;
        }

        try {
            // Hasta el final del día: un secreto que vence hoy todavía sirve
            // hoy, y decir «vence en 0 días» es más honesto que «venció».
            $fecha = CarbonImmutable::parse($vence)->endOfDay();
        } catch (\Throwable) {
            return null;
        }

        return (int) floor(now()->diffInDays($fecha, false));
    }

    /**
     * Si el error viene de la red de este servidor y no de Microsoft.
     *
     * Sirve para no mandar a nadie a reclamarle a informática por algo que está
     * de este lado. El caso típico es correr el comando fuera del contenedor,
     * donde los nombres de la red de Docker no resuelven.
     */
    public static function esFalloDeRedLocal(string $mensaje): bool
    {
        foreach (['getaddrinfo', 'name resolution', 'Connection refused', 'could not connect'] as $senal) {
            if (stripos($mensaje, $senal) !== false) {
                return true;
            }
        }

        return false;
    }
}
