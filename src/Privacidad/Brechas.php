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
        DB::transaction(function () use ($brecha): void {
            $brecha->update(['notificada_agencia_en' => now()]);
            $this->evidencia->registrar('brecha.notificada_agencia', ['brecha_id' => $brecha->getKey()]);
        });
    }

    public function notificarTitulares(Brecha $brecha): void
    {
        DB::transaction(function () use ($brecha): void {
            $brecha->update(['notificada_titulares_en' => now()]);
            $this->evidencia->registrar('brecha.notificada_titulares', ['brecha_id' => $brecha->getKey()]);
        });
    }
}
