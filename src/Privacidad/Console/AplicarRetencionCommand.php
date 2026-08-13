<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Muni\Shared\Privacidad\AplicarRetencion;

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
            $this->info('No hay titulares con plazo de retención vencido.');

            return self::SUCCESS;
        }

        $this->table(['Finalidad', 'Titulares'], array_map(
            fn (array $fila): array => [$fila['finalidad'], $fila['titulares']],
            $resumen,
        ));

        return self::SUCCESS;
    }
}
