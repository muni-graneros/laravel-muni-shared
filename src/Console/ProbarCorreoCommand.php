<?php

namespace Muni\Shared\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Muni\Shared\Correo\ConfiguracionDeCorreo;
use Muni\Shared\Correo\TransporteGraph;

/**
 * Comprueba que el sistema puede mandar correo, y avisa qué falta si no puede.
 *
 * Existe porque la forma habitual de descubrir que el correo no sale es que un
 * funcionario no logre entrar al panel: el código del segundo factor se genera,
 * se guarda, y no llega nunca. Ese aviso llega tarde y de la peor manera.
 *
 *   php artisan correo:probar
 *   php artisan correo:probar --a=alguien@municipalidadgraneros.cl
 *
 * Sin --a solo revisa la configuración y el permiso. Con --a manda un correo de
 * verdad, que es lo único que prueba que el camino completo funciona.
 */
class ProbarCorreoCommand extends Command
{
    protected $signature = 'correo:probar
        {--a= : Dirección a la que mandar un correo de prueba}';

    protected $description = 'Revisa la configuración de correo y, opcionalmente, manda un correo de prueba.';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $remitente = (string) config('mail.from.address');
        $modo = ConfiguracionDeCorreo::modoActual();

        $this->line('');
        $this->line('  <options=bold>Configuración</>');
        $this->line("    Envío        {$mailer}");
        $this->line("    Remitente    {$remitente}");

        if ($modo === 'sin-envio') {
            $this->line('');
            $this->warn('  Los correos se escriben en un archivo y no se envían.');
            $this->line('  Está bien para desarrollar. En producción: MAIL_MAILER=graph');
            $this->line('');

            return self::SUCCESS;
        }

        if ($modo !== 'graph') {
            $this->line('');
            $this->warn("  El envío está en «{$modo}», no por Microsoft Graph.");
            $this->line('  Este comando solo sabe comprobar el envío por Graph.');
            $this->line('');

            return self::SUCCESS;
        }

        return $this->probarGraph($remitente);
    }

    private function probarGraph(string $remitente): int
    {
        $tenant = (string) config('mail.mailers.graph.tenant');
        $casilla = (string) config('mail.mailers.graph.remitente');

        $this->line('    Modo         Microsoft Graph (aplicación registrada)');
        $this->line("    Casilla      {$casilla}");
        // Del identificador solo el principio: alcanza para comprobar que es el
        // que entregó informática, y no queda entero en el historial ni en una
        // captura de pantalla.
        $this->line('    Tenant       '.($tenant === '' ? '(sin configurar)' : substr($tenant, 0, 8).'…'));

        $faltan = ConfiguracionDeCorreo::credencialesQueFaltan();

        if ($faltan !== []) {
            $this->line('');
            $this->line('  <fg=red>Falta configuración:</> '.implode(', ', $faltan));
            $this->line('  Se cargan con: <options=bold>php artisan correo:configurar</>');
            $this->line('');

            return self::FAILURE;
        }

        foreach (ConfiguracionDeCorreo::problemas('graph', $remitente) as $problema) {
            $this->line("    <fg=red>·</> {$problema}");
        }

        $this->line('');
        $this->line('  <options=bold>Permiso de Microsoft</>');

        try {
            $transporte = Mail::mailer('graph')->getSymfonyTransport();

            if ($transporte instanceof TransporteGraph) {
                $transporte->permiso();
            }
        } catch (\Throwable $e) {
            return $this->explicarElFallo($e->getMessage());
        }

        $this->line('    <fg=green>Concedido</>');

        $this->mostrarVencimiento();

        $destino = $this->option('a');

        if (! is_string($destino) || $destino === '') {
            $this->line('');
            $this->line('  Falta comprobar el camino completo mandando un correo:');
            $this->line('    <options=bold>php artisan correo:probar --a=una-direccion@ejemplo.cl</>');
            $this->line('');

            return self::SUCCESS;
        }

        return $this->enviarPrueba($destino);
    }

    /**
     * Distingue un rechazo de Microsoft de un problema de este servidor.
     *
     * Atribuirlo todo a Microsoft manda a reclamarle a informática por algo que
     * está de este lado y que ellos no pueden arreglar.
     */
    private function explicarElFallo(string $mensaje): int
    {
        if (ConfiguracionDeCorreo::esFalloDeRedLocal($mensaje)) {
            $this->line('    <fg=red>No se pudo llegar a Microsoft desde este servidor.</>');
            $this->line('    '.$mensaje);
            $this->line('');
            $this->line('  Es un problema de red de este lado, no del registro de la');
            $this->line('  aplicación. Si el nombre que falla es «redis», «db» o parecido,');
            $this->line('  el comando se está corriendo fuera del contenedor:');
            $this->line('    <options=bold>docker compose exec app php artisan correo:probar</>');
            $this->line('');

            return self::FAILURE;
        }

        $this->line('    <fg=red>Microsoft no autorizó a la aplicación.</>');
        $this->line('    '.$mensaje);
        $this->line('');
        $this->line('  Suele ser el secreto vencido, o que falta el consentimiento');
        $this->line('  de administrador sobre el permiso Mail.Send.');
        $this->line('');

        return self::FAILURE;
    }

    private function mostrarVencimiento(): void
    {
        $this->line('');
        $this->line('  <options=bold>Vencimiento del permiso</>');

        $dias = ConfiguracionDeCorreo::diasHastaElVencimiento();

        if ($dias === null) {
            $this->line('    <fg=yellow>Sin anotar.</> El día que venza no va a haber aviso previo,');
            $this->line('    y ese día nadie puede entrar al panel.');
            $this->line('    Se anota en MICROSOFT_GRAPH_SECRET_VENCE (AAAA-MM-DD).');

            return;
        }

        if ($dias < 0) {
            $this->line('    <fg=red>Venció hace '.abs($dias).' días.</> Hay que pedir un secreto nuevo.');

            return;
        }

        if ($dias <= 30) {
            $this->line("    <fg=yellow>Vence en {$dias} días.</> Conviene pedir el reemplazo ahora:");
            $this->line('    el día que venza, nadie va a poder entrar al panel.');

            return;
        }

        $this->line("    <fg=green>Vence en {$dias} días.</> Se avisa cuando falten 30.");
    }

    private function enviarPrueba(string $destinatario): int
    {
        $this->line('');
        $this->line('  <options=bold>Envío de prueba</>');

        try {
            // Sin cola: acá el punto es justamente ver el error si lo hay. Si se
            // encolara, el fallo terminaría en la tabla de trabajos fallidos y
            // esta pantalla diría que todo salió bien.
            Mail::raw(
                "Esta es una prueba de la configuración de correo.\n\n"
                .'Si te llegó, el envío funciona y los códigos de acceso al panel '
                ."van a llegar sin problema.\n\n"
                .'Enviada el '.now()->format('d-m-Y \a \l\a\s H:i').'.',
                function ($mensaje) use ($destinatario) {
                    $mensaje->to($destinatario)->subject('Prueba de correo');
                },
            );
        } catch (\Throwable $e) {
            $this->line('    <fg=red>No se pudo enviar.</>');
            $this->line('    '.$e->getMessage());
            $this->line('');

            return self::FAILURE;
        }

        $this->line("    <fg=green>Enviado</> a {$destinatario}");
        $this->line('');
        $this->line('  Que salga sin error no garantiza que llegue a la bandeja de entrada.');
        $this->line('  Hay que confirmar que llegó, y revisar la carpeta de no deseados.');
        $this->line('');

        return self::SUCCESS;
    }
}
