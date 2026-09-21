<?php

declare(strict_types=1);

namespace Muni\Shared\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vacía los datos operativos/temporales del sistema (jobs, logs, progresos)
 * conservando lo esencial (usuarios, roles, permisos, tours y pasos de
 * onboarding).
 *
 *   php artisan env:clean-data              # pide confirmación
 *   php artisan env:clean-data --force      # sin preguntar
 *   php artisan env:clean-data --auditoria  # vacía también activity_log
 *
 * Portado desde `App\Console\Commands\CleanData`, idéntico byte a byte en 5 de
 * los 8 sistemas y en los dos scaffolds. Se conserva el nombre del comando
 * (`env:clean-data`), que es lo que está escrito en Makefiles, runbooks y la
 * documentación técnica de cada sistema: adoptar el paquete no cambia nada de
 * lo que alguien tipea.
 *
 * Dos diferencias con la copia local, las dos deliberadas:
 *
 * - La lista de tablas ya no es una constante sino `config('datos-operativos')`.
 *   La constante era exactamente lo que iba a hacer que el primer sistema con
 *   una tabla operativa propia volviera a bifurcar el archivo.
 * - Las claves foráneas se desactivan con `Schema::withoutForeignKeyConstraints()`
 *   y no con `SET FOREIGN_KEY_CHECKS=0`, que es SQL de MySQL/MariaDB y hace
 *   reventar el comando en cualquier otro motor (y volvía imposible probarlo
 *   en la suite en SQLite del paquete).
 *
 * Es destructivo: `ConfirmableTrait` lo frena en producción salvo `--force`.
 */
class LimpiarDatosOperativosCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'env:clean-data
        {--force : Ejecutar sin confirmación interactiva}
        {--auditoria : Vaciar también el registro de auditoría (activity_log)}';

    protected $description = 'Vacía los datos operativos (jobs, logs de progreso) conservando usuarios, roles/permisos y onboarding.';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $tablas = $this->tablas();

        $this->warn('Se vaciarán estas tablas operativas (se CONSERVAN usuarios, roles, permisos y tours/pasos):');
        $total = 0;
        foreach ($tablas as $t) {
            if (Schema::hasTable($t)) {
                $n = DB::table($t)->count();
                $total += $n;
                $this->line(sprintf('  - %-28s %s filas', $t, number_format($n, 0, ',', '.')));
            }
        }

        if ($total === 0) {
            $this->info('No hay datos operativos que limpiar.');

            return self::SUCCESS;
        }

        Schema::withoutForeignKeyConstraints(function () use ($tablas): void {
            foreach ($tablas as $t) {
                if (Schema::hasTable($t)) {
                    DB::table($t)->delete();
                }
            }
        });

        // Limpiar caché
        $this->info('🧹 Limpiando caché de Laravel...');
        Artisan::call('cache:clear');

        $this->newLine();
        $this->info("Listo. Se vaciaron {$total} filas de datos operativos.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function tablas(): array
    {
        /** @var list<string> $tablas */
        $tablas = array_values(array_filter(
            (array) config('datos-operativos.tablas', []),
            is_string(...),
        ));

        if ($this->option('auditoria')) {
            /** @var list<string> $auditoria */
            $auditoria = array_values(array_filter(
                (array) config('datos-operativos.tablas_de_auditoria', []),
                is_string(...),
            ));

            $tablas = array_merge($tablas, $auditoria);
        }

        return $tablas;
    }
}
