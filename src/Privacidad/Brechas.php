<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Brecha;

/**
 * El canal oficial de la Agencia todavía no existe, así que el módulo no
 * automatiza el envío: registra los hitos y deja el informe listo, que es lo
 * que se pide acreditar.
 */
class Brechas
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /** @param array<string, mixed> $datos */
    public function registrar(string $descripcion, array $datos = []): Brecha
    {
        // Igual que en Consentimientos/AplicarRetencion: si el registro de
        // evidencia falla, la fila tampoco queda, porque una brecha sin
        // bitácora no sirve para acreditar el cumplimiento del plazo legal.
        return DB::transaction(function () use ($descripcion, $datos) {
            $brecha = Brecha::create([
                'sistema' => (string) config('privacidad.sistema'),
                'detectada_en' => $datos['detectada_en'] ?? now(),
                'descripcion' => $descripcion,
                'naturaleza' => $datos['naturaleza'] ?? null,
                'categorias_afectadas' => $datos['categorias_afectadas'] ?? null,
                'titulares_estimados' => $datos['titulares_estimados'] ?? null,
                // Sin cast a bool: si quien llama no dice nada, el riesgo
                // queda sin evaluar (null), no "sin riesgo" (false). Es la
                // tercera vez en este módulo que un default silencioso
                // habría tenido consecuencia legal (compárese con la falta
                // de finalidad accesoria en Consentimientos y el resolvedor
                // ausente en AplicarRetencion): acá, archivar como "no es
                // alto" una brecha que nadie evaluó es exactamente el modo
                // en que un titular que debía ser notificado no lo es.
                'riesgo_alto' => $datos['riesgo_alto'] ?? null,
                'medidas' => $datos['medidas'] ?? null,
            ]);

            $this->evidencia->registrar('brecha.registrada', ['brecha_id' => $brecha->getKey()]);

            return $brecha;
        });
    }

    public function notificarAgencia(Brecha $brecha): void
    {
        // La fecha del hito es exactamente contra lo que un fiscalizador mide
        // el plazo legal desde la detección: un segundo clic en el panel no
        // puede correrla hacia adelante y convertir un aviso tardío en uno a
        // tiempo. No se lanza excepción —el doble clic no es un error que valga
        // la pena mostrarle a quien está gestionando una brecha—, simplemente
        // no pasa nada nuevo y tampoco se registra nada nuevo.
        $this->sellar($brecha, 'notificada_agencia_en', 'brecha.notificada_agencia');
    }

    public function notificarTitulares(Brecha $brecha): void
    {
        // Mismo criterio que notificarAgencia(): son dos hitos independientes,
        // y cada uno se sella una sola vez.
        $this->sellar($brecha, 'notificada_titulares_en', 'brecha.notificada_titulares');
    }

    /**
     * Sella un hito de notificación una sola vez, de verdad.
     *
     * El candado NO puede ser `if ($brecha->$columna !== null) return;`: eso
     * pregunta por la copia en memoria que recibió el método, y dos requests
     * concurrentes —o una pestaña abierta desde antes— traen cada una su propia
     * instancia con el hito todavía en null, pasan las dos y la segunda pisa la
     * fecha. Es el mismo doble clic que se quería evitar, sólo que más difícil
     * de reproducir. La condición viaja dentro del UPDATE (`whereNull`), que la
     * base resuelve de forma atómica, y el conteo de filas afectadas es lo que
     * decide si corresponde escribir evidencia.
     */
    private function sellar(Brecha $brecha, string $columna, string $evento): void
    {
        DB::transaction(function () use ($brecha, $columna, $evento): void {
            $selladas = Brecha::query()
                ->whereKey($brecha->getKey())
                ->whereNull($columna)
                ->update([$columna => now()]);

            if ($selladas === 0) {
                return;
            }

            $this->evidencia->registrar($evento, ['brecha_id' => $brecha->getKey()]);
        });

        // La instancia que trajo quien llama queda con la fecha real —la del
        // sello, sea de esta llamada o de la que ganó la carrera—, para que el
        // panel no siga mostrando el hito pendiente después de notificarlo.
        $brecha->refresh();
    }
}
