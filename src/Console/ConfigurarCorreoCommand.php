<?php

namespace Muni\Shared\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Carga en el .env las credenciales del correo por Microsoft Graph.
 *
 * El secreto se teclea, no se pasa como argumento: así no queda en el historial
 * del shell, ni en la lista de procesos, ni en la pantalla. Un secreto escrito
 * una vez en una terminal se queda en el historial hasta que alguien se acuerda
 * de borrarlo, y nadie se acuerda.
 *
 *   php artisan correo:configurar
 *
 * Las tres credenciales son las mismas en todos los sistemas del ecosistema;
 * lo único que cambia es la casilla remitente.
 */
class ConfigurarCorreoCommand extends Command
{
    protected $signature = 'correo:configurar';

    protected $description = 'Carga en el .env las credenciales del correo por Microsoft Graph.';

    public function handle(): int
    {
        $env = base_path('.env');

        if (! is_file($env)) {
            $this->error("No hay un archivo .env en {$env}.");

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  Credenciales del registro de aplicación de Entra ID.');
        $this->line('  Los identificadores se ven; el secreto no.');
        $this->line('');

        $tenant = $this->pedirGuid('Id. de directorio (inquilino)');
        $cliente = $this->pedirGuid('Id. de aplicación (cliente)');
        $secreto = $this->pedirSecreto();

        if ($secreto === null) {
            return self::FAILURE;
        }

        $remitente = text(
            label: 'Casilla remitente',
            placeholder: 'no-reply@municipalidadgraneros.cl',
            required: true,
            validate: fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) === false
                ? 'No parece una dirección de correo.'
                : null,
        );

        $vence = text(
            label: 'Fecha en que vence el secreto (AAAA-MM-DD)',
            hint: 'Está en la columna «Expira» del portal. Sin ella no hay aviso previo.',
            required: true,
            validate: fn (string $v) => strtotime($v) === false
                ? 'No se entiende esa fecha. Se espera AAAA-MM-DD.'
                : null,
        );

        $this->escribir($env, [
            'MAIL_MAILER' => 'graph',
            'MICROSOFT_GRAPH_TENANT_ID' => $tenant,
            'MICROSOFT_GRAPH_CLIENT_ID' => $cliente,
            'MICROSOFT_GRAPH_CLIENT_SECRET' => $secreto,
            'MICROSOFT_GRAPH_REMITENTE' => $remitente,
            'MICROSOFT_GRAPH_SECRET_VENCE' => date('Y-m-d', (int) strtotime($vence)),
            'MAIL_FROM_ADDRESS' => $remitente,
        ]);

        $this->line('');
        $this->line('  Escrito en .env. El secreto no se muestra ni queda en el historial.');
        $this->line('');
        $this->line('  Para que tome efecto y comprobarlo:');
        $this->line('    <options=bold>php artisan config:clear</>');
        $this->line('    <options=bold>php artisan correo:probar</>');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Un identificador de Entra ID es un GUID. Comprobarlo evita el error más
     * común y más difícil de diagnosticar: pegar el valor de un campo en el
     * otro, que no da ningún síntoma hasta que falla la autenticación.
     */
    private function pedirGuid(string $etiqueta): string
    {
        return text(
            label: $etiqueta,
            required: true,
            validate: fn (string $v) => self::esGuid(trim($v))
                ? null
                : 'No parece un identificador válido (se espera un GUID).',
            transform: fn (string $v) => trim($v),
        );
    }

    private function pedirSecreto(): ?string
    {
        // Se pide dos veces porque un secreto mal pegado no da ningún error
        // hasta que alguien no puede entrar al panel.
        $secreto = password(label: 'Secreto de cliente', required: true);
        $confirmacion = password(label: 'Otra vez, para confirmar', required: true);

        if ($secreto !== $confirmacion) {
            $this->error('  Los dos secretos no coinciden. No se escribió nada.');

            return null;
        }

        // El portal muestra el «Id. del secreto» al lado del valor, y se parece
        // a un GUID. Copiar ese en vez del valor es un error frecuente: el
        // registro parece bien configurado y la autenticación falla sin decir
        // por qué.
        if (self::esGuid($secreto) && ! $this->confirm('  Lo que pegaste es un GUID, y el VALOR del secreto no lo es (el «Id. del secreto» sí). ¿Seguir igual?', false)) {
            return null;
        }

        return $secreto;
    }

    private static function esGuid(string $valor): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $valor) === 1;
    }

    /**
     * @param  array<string, string>  $valores
     */
    private function escribir(string $env, array $valores): void
    {
        $contenido = (string) file_get_contents($env);

        foreach ($valores as $clave => $valor) {
            // Entre comillas: los secretos de Microsoft traen caracteres que el
            // .env interpretaría de otra forma.
            $linea = $clave.'="'.$valor.'"';
            $patron = '/^'.preg_quote($clave, '/').'=.*$/m';

            $contenido = preg_match($patron, $contenido) === 1
                ? (string) preg_replace($patron, addcslashes($linea, '\\$'), $contenido, 1)
                : rtrim($contenido, "\n")."\n".$linea."\n";
        }

        // Se vuelca sobre el archivo original en vez de moverlo encima: así
        // conserva sus permisos y su dueño. En producción el .env lo lee
        // www-data, y un archivo nuevo con otro dueño deja al sistema sin
        // configuración.
        file_put_contents($env, $contenido);
    }
}
