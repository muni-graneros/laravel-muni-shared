<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
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
        private readonly Edades $edades,
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

        // Se resuelve una sola vez, antes de la comprobación de edad, porque la
        // comprobación y la fila tienen que mirar exactamente el mismo valor: si
        // el chequeo leyera $opciones en crudo y la fila aplicara el default,
        // omitir la opción sería el camino por el que un menor consiente solo.
        $otorgadoPor = $this->solicitanteDe($opciones);

        $this->exigirRegimenDeNna($titular, $finalidad, $otorgadoPor);

        // La transacción sigue existiendo por la razón 2, no por la 1: si el registro
        // de evidencia falla después de crear la fila, todo se revierte, porque un
        // consentimiento sin evidencia contradice el propósito del módulo. Quien
        // impide dos vigentes a la vez para el mismo (titular, finalidad) es el
        // índice único sobre `vigente_clave` (ver la migración), no el orden de
        // llamadas: dos otorgar() concurrentes que ambos intenten insertar la misma
        // clave chocan en la base de datos, no en la aplicación.
        return DB::transaction(function () use ($titular, $finalidad, $medio, $opciones, $otorgadoPor) {
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
                'otorgado_por' => $otorgadoPor,
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
     * Quién otorga, resuelto a enum antes de que nadie decida nada con él.
     *
     * La columna está casteada, así que un adoptante puede pasar el string y la
     * fila se crea igual; si el régimen de NNA comparara contra la instancia del
     * enum, ese camino legítimo quedaría rechazado por escribirse distinto.
     *
     * @param  array<string, mixed>  $opciones
     */
    private function solicitanteDe(array $opciones): Solicitante
    {
        $valor = $opciones['otorgado_por'] ?? Solicitante::Titular;

        // Un valor que no sea un caso del enum revienta acá con ValueError, que
        // es lo mismo que hacía el cast al crear la fila, solo que antes.
        return $valor instanceof Solicitante ? $valor : Solicitante::from((string) $valor);
    }

    /**
     * Régimen reforzado de niños, niñas y adolescentes.
     *
     * Va antes de la transacción porque no toca nada: rechaza o deja pasar.
     *
     * Solo se aplica a quien implementa `TitularDeDatos`. La firma de otorgar()
     * admite cualquier `Model`, y a quien no firmó el contrato no se le puede
     * preguntar la fecha de nacimiento ni exigir que la tenga.
     */
    private function exigirRegimenDeNna(Model $titular, Finalidad $finalidad, Solicitante $otorgadoPor): void
    {
        if (! $titular instanceof TitularDeDatos) {
            return;
        }

        $esNNA = $this->edades->esNNA($titular);

        // null es "no se sabe", no "es adulto". Se rechaza acá y no más abajo
        // porque sin la edad no se puede evaluar ninguna de las dos reglas que
        // siguen: ni si la finalidad lo admite, ni quién tiene que firmar.
        if ($esNNA === null) {
            throw new EdadNoAcreditada(
                'No se puede pedir consentimiento sin saber si el titular es mayor de edad: '
                .'la edad no está acreditada en este sistema.',
            );
        }

        if (! $esNNA) {
            return;
        }

        if (! $finalidad->admite_nna) {
            throw new FinalidadInvalida(
                "La finalidad «{$finalidad->codigo}» no admite el tratamiento de menores de edad.",
            );
        }

        // Apoderado tampoco sirve, y no es un olvido: un menor no puede otorgar
        // mandato, así que un apoderado suyo no existe jurídicamente.
        if ($otorgadoPor !== Solicitante::RepresentanteLegal) {
            throw new EdadNoAcreditada(
                'El consentimiento de un menor de edad lo otorga su representante legal, no él mismo.',
            );
        }
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
