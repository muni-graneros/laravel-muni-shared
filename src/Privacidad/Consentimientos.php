<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private readonly RegistroDeEvidencia $evidencia,
        private readonly Textos $textos,
    ) {}

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

        // La transacción sigue existiendo por la razón 2, no por la 1: si el registro
        // de evidencia falla después de crear la fila, todo se revierte, porque un
        // consentimiento sin evidencia contradice el propósito del módulo. Quien
        // impide dos vigentes a la vez para el mismo (titular, finalidad) es el
        // índice único sobre `vigente_clave` (ver la migración), no el orden de
        // llamadas: dos otorgar() concurrentes que ambos intenten insertar la misma
        // clave chocan en la base de datos, no en la aplicación.
        return DB::transaction(function () use ($titular, $finalidad, $medio, $opciones) {
            // Si había uno vigente, se cierra primero.
            $this->revocar($titular, $finalidad);

            $consentimiento = Consentimiento::create([
                'titular_type' => $titular->getMorphClass(),
                'titular_id' => $titular->getKey(),
                'finalidad_id' => $finalidad->getKey(),
                'vigente_clave' => $this->claveVigente($titular, $finalidad),
                'otorgado_en' => now(),
                'medio' => $medio,
                'evidencia_path' => $opciones['evidencia_path'] ?? null,
                'version_texto' => $opciones['version_texto'] ?? null,
                // A la fila exacta, no a un string suelto: `version_texto` no
                // obligaba a nadie a llenarlo y no probaba nada. Ausente el
                // código, o sin texto vigente con ese código, queda null: es el
                // camino de los consentimientos en papel de antes de este texto,
                // que no tienen con qué acreditar la versión.
                'texto_id' => isset($opciones['codigo_texto'])
                    ? $this->textos->vigente((string) $opciones['codigo_texto'])?->getKey()
                    : null,
                // Enum y no texto: es una de las dos columnas que el barrido de
                // anonimización conserva por ser categórica, y esa promesa solo
                // se sostiene si la columna no puede recibir el nombre del
                // representante (ver Solicitante).
                'otorgado_por' => $opciones['otorgado_por'] ?? Solicitante::Titular,
                'user_id' => Auth::id(),
                'ip_hash' => isset($opciones['ip']) ? hash('sha256', (string) $opciones['ip']) : null,
            ]);

            $this->evidencia->registrar('consentimiento.otorgado', [
                'finalidad' => $finalidad->codigo,
                'medio' => $medio->value,
            ], $titular);

            return $consentimiento;
        });
    }

    public function revocar(Model $titular, Finalidad $finalidad): void
    {
        DB::transaction(function () use ($titular, $finalidad) {
            // vigente_clave se limpia junto con revocado_en: las dos columnas se
            // mueven siempre juntas, o la fila revocada seguiría bloqueando el índice
            // único e impidiendo que se otorgue un consentimiento nuevo.
            $afectados = Consentimiento::query()
                ->where('titular_type', $titular->getMorphClass())
                ->where('titular_id', $titular->getKey())
                ->where('finalidad_id', $finalidad->getKey())
                ->vigentes()
                ->update(['revocado_en' => now(), 'vigente_clave' => null]);

            if ($afectados > 0) {
                $this->evidencia->registrar('consentimiento.revocado', [
                    'finalidad' => $finalidad->codigo,
                ], $titular);
            }
        });
    }

    public function vigente(Model $titular, Finalidad $finalidad): bool
    {
        return Consentimiento::query()
            ->where('titular_type', $titular->getMorphClass())
            ->where('titular_id', $titular->getKey())
            ->where('finalidad_id', $finalidad->getKey())
            ->vigentes()
            ->exists();
    }

    /**
     * Identidad determinística de "consentimiento vigente para este (titular,
     * finalidad)". Se hashea porque `titular_type` puede ser un nombre de clase
     * completamente calificado y exceder el límite de índice único de MySQL
     * (767 bytes en InnoDB con row_format antiguo); sha1 da un largo fijo de 40
     * caracteres, cómodo bajo cualquier backend soportado.
     *
     * Se deriva de getMorphClass() y no de `::class` por la misma razón que la
     * columna `titular_type`: bajo un morph map que mapea varias clases al mismo
     * alias, una clave hecha con el FQCN haría que la unicidad se calculara POR
     * CLASE mientras revocar() y vigente() —que filtran por la columna— operan
     * POR ALIAS. El índice dejaría entrar dos filas vigentes para el mismo
     * (titular, finalidad) desde dos clases distintas, que es exactamente el
     * estado que ese índice existe para hacer imposible.
     */
    private function claveVigente(Model $titular, Finalidad $finalidad): string
    {
        return sha1($titular->getMorphClass().'|'.$titular->getKey().'|'.$finalidad->getKey());
    }
}
