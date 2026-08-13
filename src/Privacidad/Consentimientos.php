<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * El consentimiento solo aplica a finalidades accesorias: el registro base se
 * funda en el ejercicio de funciones legales del municipio y no se revoca, o un
 * ciudadano molesto podría borrar su propia inscripción en un registro comunal.
 */
class Consentimientos
{
    public function __construct(private readonly RegistroDeEvidencia $evidencia) {}

    /** @param array<string, mixed> $opciones */
    public function otorgar(
        Model $titular,
        Finalidad $finalidad,
        MedioDeConsentimiento $medio,
        array $opciones = [],
    ): Consentimiento {
        if (! $finalidad->es_accesoria) {
            throw new FinalidadInvalida(
                "La finalidad «{$finalidad->codigo}» no es accesoria: se funda en "
                .$finalidad->base_licitud->etiqueta().' y no admite consentimiento.',
            );
        }

        // Si había uno vigente, se cierra: dos vigentes a la vez harían ambiguo
        // qué texto aceptó el titular.
        $this->revocar($titular, $finalidad);

        $consentimiento = Consentimiento::create([
            'titular_type' => $titular::class,
            'titular_id' => $titular->getKey(),
            'finalidad_id' => $finalidad->getKey(),
            'otorgado_en' => now(),
            'medio' => $medio,
            'evidencia_path' => $opciones['evidencia_path'] ?? null,
            'version_texto' => $opciones['version_texto'] ?? null,
            'otorgado_por' => $opciones['otorgado_por'] ?? 'titular',
            'ip_hash' => isset($opciones['ip']) ? hash('sha256', (string) $opciones['ip']) : null,
        ]);

        $this->evidencia->registrar('consentimiento.otorgado', [
            'finalidad' => $finalidad->codigo,
            'medio' => $medio->value,
        ], $titular);

        return $consentimiento;
    }

    public function revocar(Model $titular, Finalidad $finalidad): void
    {
        $afectados = Consentimiento::query()
            ->where('titular_type', $titular::class)
            ->where('titular_id', $titular->getKey())
            ->where('finalidad_id', $finalidad->getKey())
            ->vigentes()
            ->update(['revocado_en' => now()]);

        if ($afectados > 0) {
            $this->evidencia->registrar('consentimiento.revocado', [
                'finalidad' => $finalidad->codigo,
            ], $titular);
        }
    }

    public function vigente(Model $titular, Finalidad $finalidad): bool
    {
        return Consentimiento::query()
            ->where('titular_type', $titular::class)
            ->where('titular_id', $titular->getKey())
            ->where('finalidad_id', $finalidad->getKey())
            ->vigentes()
            ->exists();
    }
}
