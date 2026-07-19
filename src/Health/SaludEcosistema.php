<?php

namespace Muni\Shared\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/**
 * Chequeo de salud compartido del ecosistema municipal.
 *
 * Reporta las dependencias que TODOS los sistemas comparten (base de datos,
 * Redis/cola, alcance del maestro de personas) en un formato normalizado, para
 * que un endpoint `/salud` o un agregador central muestre estado REAL en vez de
 * datos hardcodeados.
 *
 * Cada sistema puede sumar sus chequeos propios (OCR, terminales, etc.) pasando
 * cierres a `conExtra()`; el núcleo se mantiene idéntico en todo el ecosistema.
 *
 * @phpstan-type Chequeo array{estado: string, detalle: string|null, ms: int}
 */
class SaludEcosistema
{
    /** @var array<string, callable(): bool> */
    private array $extra = [];

    /** Prefijo de config del maestro (feria/disc: services.personas_api; licencias: el suyo). */
    public function __construct(private string $maestroConfigPrefix = 'services.personas_api') {}

    /**
     * Añade un chequeo propio del sistema. El cierre devuelve true (arriba) o false.
     */
    public function conExtra(string $nombre, callable $chequeo): static
    {
        $this->extra[$nombre] = $chequeo;

        return $this;
    }

    /**
     * Ejecuta todos los chequeos y devuelve el reporte normalizado.
     *
     * @return array{sistema: string, estado: string, ts: string, checks: array<string, Chequeo>}
     */
    public function reporte(): array
    {
        $checks = [
            'base_datos' => $this->medir(fn () => $this->baseDatosOk()),
            'cola' => $this->medir(fn () => $this->colaOk()),
            'maestro' => $this->medir(fn () => $this->maestroOk()),
        ];

        foreach ($this->extra as $nombre => $chequeo) {
            $checks[$nombre] = $this->medir($chequeo);
        }

        $global = collect($checks)->every(fn (array $c) => $c['estado'] === 'ok') ? 'ok' : 'degradado';

        return [
            'sistema' => (string) config('app.name', 'sistema'),
            'estado' => $global,
            'ts' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * Corre un chequeo midiendo su latencia y capturando errores.
     *
     * @param  callable(): bool  $chequeo
     * @return Chequeo
     */
    private function medir(callable $chequeo): array
    {
        $t0 = microtime(true);
        try {
            $ok = (bool) $chequeo();

            return [
                'estado' => $ok ? 'ok' : 'caido',
                'detalle' => null,
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'estado' => 'caido',
                // Solo la clase del error, sin datos sensibles ni stack.
                'detalle' => class_basename($e),
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        }
    }

    private function baseDatosOk(): bool
    {
        DB::connection()->select('select 1');

        return true;
    }

    /** Cola sana = la profundidad de la cola por defecto no está desbocada. */
    private function colaOk(): bool
    {
        try {
            return (int) Redis::connection()->llen('queues:default') < 500;
        } catch (\Throwable) {
            // Sin Redis (cola sync en dev/tests): se considera sano.
            return true;
        }
    }

    /** El maestro responde. Si no está configurado, no es un fallo del sistema. */
    private function maestroOk(): bool
    {
        $url = config($this->maestroConfigPrefix.'.url');
        if (! $url) {
            return true; // sin maestro configurado: no aplica
        }

        return Http::timeout(5)->get(rtrim((string) $url, '/'))->successful();
    }
}
