<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Muni\Shared\Privacidad\AplicarRetencion;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\NingunTitularVencido;

class AplicarRetencionCommand extends Command
{
    protected $signature = 'privacidad:aplicar-retencion {--ejecutar : Aplica los cambios de verdad}';

    protected $description = 'Anonimiza y purga los datos cuyo plazo de retención venció';

    public function handle(AplicarRetencion $retencion): int
    {
        // El destructivo es opt-in: nadie descubre en producción que un cron
        // llevaba semanas borrando lo que no correspondía.
        $simulacion = ! $this->option('ejecutar');

        if ($simulacion) {
            $this->warn('Modo simulación: no se modificará ningún dato. Usar --ejecutar para aplicar.');
        }

        $resumen = $retencion->ejecutar($simulacion);

        if ($resumen === []) {
            // "No hay vencidos" y "nunca se miró" se ven idénticos desde afuera,
            // y las dos causas de abajo son el estado normal de un sistema recién
            // instalado. Un cron diario informando cumplimiento de un módulo que
            // no está corriendo es peor que no tener el cron: da por cubierto lo
            // que nadie cubrió. Igual que `privacidad:rat`, se dice en voz alta.
            $this->avisarSiNoHayNadaQueRevisar();

            $this->info('No hay titulares con plazo de retención vencido.');

            return self::SUCCESS;
        }

        $this->table(['Finalidad', 'Titulares'], array_map(
            fn (array $fila): array => [$fila['finalidad'], $fila['titulares']],
            $resumen,
        ));

        return self::SUCCESS;
    }

    /**
     * Las dos razones por las que la retención puede no haber revisado nada.
     * Van en el comando y no en `AplicarRetencion` a propósito: el servicio
     * devuelve un resumen, y quien necesita el aviso es la persona (o el correo
     * del cron) que lee la salida.
     */
    private function avisarSiNoHayNadaQueRevisar(): void
    {
        $sistema = (string) config('privacidad.sistema');

        $conPlazo = Finalidad::query()
            ->delSistema($sistema)
            ->where('activa', true)
            ->whereNotNull('plazo_retencion_meses')
            ->count();

        if ($conPlazo === 0) {
            $this->warn(
                "El sistema «{$sistema}» no declaró ninguna finalidad vigente con plazo de retención: "
                .'no se revisó nada. Sembrar las finalidades con su `plazo_retencion_meses`.',
            );
        }

        if (app(ResuelveTitularesVencidos::class) instanceof NingunTitularVencido) {
            $this->warn(
                'El sistema no implementó ResuelveTitularesVencidos (sigue el enlace por defecto, '
                .'que nunca devuelve titulares): la retención NO está operativa.',
            );
        }
    }
}
