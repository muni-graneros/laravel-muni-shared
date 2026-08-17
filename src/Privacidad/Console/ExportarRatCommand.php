<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Muni\Shared\Privacidad\Modelos\DecisionAutomatizada;
use Muni\Shared\Privacidad\Modelos\Encargado;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * El registro de actividades de tratamiento es lo primero que pide una
 * fiscalización. Se genera desde la base y no desde un documento, para que no
 * pueda quedar desactualizado sin que nadie se entere.
 */
class ExportarRatCommand extends Command
{
    protected $signature = 'privacidad:rat {--json : Emite el RAT en JSON}';

    protected $description = 'Exporta el registro de actividades de tratamiento del sistema';

    public function handle(): int
    {
        $sistema = (string) config('privacidad.sistema');

        // No se filtra por `activa`: una finalidad dada de baja sigue siendo
        // parte del historial de tratamiento, y el RAT existe para que una
        // fiscalización vea lo que el sistema realmente hizo, no una versión
        // recortada. El estado se muestra, nunca se oculta.
        //
        // `with('encargados')` para no convertir el mapeo de abajo en un N+1:
        // sin esto, cada finalidad dispara su propia consulta a la tabla pivote.
        $finalidades = Finalidad::query()->delSistema($sistema)->with('encargados')->orderBy('codigo')->get();

        // Se lee una sola vez y se reutiliza en los dos caminos (json y tabla):
        // es la misma pregunta —¿qué decisiones automatizadas toma este
        // sistema?— contestada en dos formatos, no dos preguntas distintas.
        // `with('finalidad')` por el mismo motivo que `with('encargados')`
        // arriba: sin él, el `finalidad_id`.codigo de cada decisión dispara su
        // propia consulta al mapear más abajo.
        $decisiones = DecisionAutomatizada::query()->delSistema($sistema)->with('finalidad')->get();

        // Se calcula una sola vez y se reutiliza en los dos caminos: es el
        // mismo estado de cumplimiento —¿hay encargados sin contrato vigente?,
        // ¿está completo el responsable?, ¿se pudo identificar el sistema?—
        // contestado en dos formatos, no dos preguntas distintas. Antes solo
        // se calculaba dentro del camino de tabla, y el de --json no tenía
        // forma de decir nada al respecto sin ensuciar la salida.
        $sinContrato = Encargado::query()->where('sistema', $sistema)->sinContratoVigente()->pluck('nombre');
        $advertencias = $this->advertencias($sistema, $finalidades, $sinContrato);

        // El chequeo de --json va primero: quien redirige la salida a
        // `json_decode` no puede recibir una línea de advertencia en texto
        // plano solo porque el sistema no declaró finalidades todavía. Por
        // eso las advertencias no se imprimen como líneas sueltas acá: viajan
        // DENTRO del documento, en la clave `advertencias`, que es la única
        // forma de avisar sin escribir nada fuera del JSON.
        if ($this->option('json')) {
            $this->line(json_encode([
                'sistema' => $sistema,
                'generado_en' => now()->toIso8601String(),
                'responsable' => config('privacidad.responsable'),
                'finalidades' => $finalidades->map(fn (Finalidad $f): array => [
                    'codigo' => $f->codigo,
                    'nombre' => $f->nombre,
                    'descripcion' => $f->descripcion,
                    'base_licitud' => $f->base_licitud->value,
                    'norma_habilitante' => $f->norma_habilitante,
                    'excepcion_dato_sensible' => $f->excepcion_dato_sensible?->value,
                    'es_accesoria' => $f->es_accesoria,
                    'activa' => $f->activa,
                    'plazo_retencion_meses' => $f->plazo_retencion_meses,
                    'categorias_datos' => $f->categorias_datos,
                    // `destinatarios` no se exporta: es la columna que
                    // `encargados` (abajo, con contrato y vigencia) reemplazó.
                    // En el sistema verificado en vivo venía `null` en las
                    // seis finalidades, incluida la que sí tiene un
                    // `Encargado` declarado (Maestro de Personas) — es decir,
                    // exportar el legado junto al reemplazo no sumaba
                    // información, la contradecía: alguien que solo mirara
                    // `destinatarios` leería «nadie» donde `encargados` dice
                    // que sí hay alguien. Ver docs/privacidad/verificacion-
                    // adopcion-discapacidad.md, hallazgo 9.
                    'encargados' => $f->encargados->map(fn (Encargado $e): array => [
                        'nombre' => $e->nombre,
                        'rol' => $e->rol,
                        'contrato_vence_en' => $e->contrato_vence_en?->toDateString(),
                    ])->all(),
                ])->all(),
                // Siempre presente, aunque esté vacía: un arreglo vacío dice
                // «se revisó y no hay ninguna»; una clave ausente diría «este
                // RAT no llegó a contestar la pregunta». Son respuestas
                // distintas ante una fiscalización y el export no las mezcla.
                'decisiones_automatizadas' => $decisiones->map(fn (DecisionAutomatizada $d): array => [
                    'descripcion' => $d->descripcion,
                    'logica' => $d->logica,
                    'consecuencias' => $d->consecuencias,
                    'permite_revision_humana' => $d->permite_revision_humana,
                    'finalidad' => $d->finalidad?->codigo,
                ])->all(),
                // Mismo principio que `decisiones_automatizadas`: siempre
                // presente. Un arreglo vacío es la respuesta «se revisó y no
                // hay nada que objetar»; uno con contenido dice exactamente
                // qué le falta a este RAT para ser confiable. Sin esta clave,
                // un sistema sin PRIVACIDAD_SISTEMA configurado producía un
                // JSON sintácticamente idéntico al de un sistema que en
                // efecto no trata ningún dato — indistinguibles para quien
                // solo mira el documento.
                'advertencias' => $advertencias,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            // Sin sistema identificado, este documento no es un RAT vacío
            // legítimo: es un RAT que no se pudo generar. Un `$?` no cero es
            // la señal que un script que archiva o presenta esta salida SÍ
            // puede comprobar sin tener que parsear el JSON — y la clave
            // `advertencias` de arriba es la que puede comprobar quien solo
            // se quedó con el documento y no con el código de salida del
            // proceso que lo generó. Las dos señales cubren a los dos
            // consumidores distintos; ninguna por sí sola alcanza a los dos.
            //
            // No se usa esto para el caso de finalidades vacías con sistema
            // sí configurado: ese es un estado real y transitorio de una
            // adopción recién instalada (antes del seeder), no una
            // configuración rota, y ya queda declarado en `advertencias`.
            return $sistema === '' ? self::FAILURE : self::SUCCESS;
        }

        if ($finalidades->isEmpty()) {
            $this->warn("El sistema «{$sistema}» no declaró ninguna finalidad de tratamiento.");

            if (($responsable = $this->responsableIncompleto()) !== null) {
                $this->warn($responsable);
            }

            return self::SUCCESS;
        }

        $this->info("RAT del sistema «{$sistema}» — ".config('privacidad.responsable.nombre'));

        $this->table(
            ['Código', 'Finalidad', 'Base de licitud', 'Norma', 'Causal dato sensible', 'Retención (meses)', 'Estado'],
            $finalidades->map(fn (Finalidad $f): array => [
                $f->codigo,
                $f->nombre,
                $f->base_licitud->etiqueta(),
                $f->norma_habilitante ?? '—',
                $f->excepcion_dato_sensible?->etiqueta() ?? '—',
                $f->plazo_retencion_meses ?? 'sin plazo',
                $f->activa ? 'Vigente' : 'Dada de baja',
            ])->all(),
        );

        // Después de la tabla y no antes: son avisos sobre el estado del
        // trámite —contratos, identificación del responsable—, no sobre las
        // finalidades, y mezclarlos adentro de la tabla (que es por
        // finalidad) le haría perder la fila que le corresponde a cada
        // encargado —una finalidad puede tener varios, y un encargado varias
        // finalidades—.
        if ($sinContrato->isNotEmpty()) {
            $this->warn('Encargados sin contrato al día: '.$sinContrato->implode(', ')
                .'. La ley exige contrato con cada encargado del tratamiento.');
        }

        if (($responsable = $this->responsableIncompleto()) !== null) {
            $this->warn($responsable);
        }

        // Se declara el caso vacío en vez de callar: un RAT mudo sobre el
        // tema es indistinguible de uno que no llegó a revisarlo, y la ley da
        // derecho a no ser objeto de decisiones automatizadas con efectos
        // significativos — contestar «ninguna» es contestar la pregunta.
        if ($decisiones->isEmpty()) {
            $this->info("El sistema «{$sistema}» no declara decisiones automatizadas con efectos significativos sobre los titulares.");

            return self::SUCCESS;
        }

        $sinRevision = $decisiones->where('permite_revision_humana', false)->pluck('descripcion');

        if ($sinRevision->isNotEmpty()) {
            $this->warn('Decisiones automatizadas sin revisión humana: '.$sinRevision->implode(', ')
                .'. El titular tiene derecho a pedir intervención humana.');
        }

        return self::SUCCESS;
    }

    /**
     * Junta, en un solo lugar, todo lo que hace que este RAT no sea confiable
     * tal cual está: sistema sin identificar, sin finalidades, encargados sin
     * contrato vigente, responsable incompleto. El camino de tabla imprime
     * cada una por separado con `$this->warn()`; el de --json las empaqueta
     * todas en la clave `advertencias` del documento, porque ahí no hay línea
     * suelta que imprimir sin romper el JSON.
     *
     * @param  Collection<int, Finalidad>  $finalidades
     * @param  Collection<int, string>  $sinContrato
     * @return list<string>
     */
    private function advertencias(string $sistema, Collection $finalidades, Collection $sinContrato): array
    {
        $advertencias = [];

        if ($sistema === '') {
            // Distinto del caso de abajo a propósito: acá no es que el
            // sistema revisó su tratamiento y no declaró nada, es que no se
            // pudo saber DE QUÉ sistema se está hablando. Un documento así no
            // puede leerse como «este sistema no trata datos» — no identifica
            // ningún sistema.
            $advertencias[] = 'PRIVACIDAD_SISTEMA no está configurado: este RAT no identifica ningún sistema '
                .'y no puede tomarse como la declaración de que no se trata información personal.';
        } elseif ($finalidades->isEmpty()) {
            $advertencias[] = "El sistema «{$sistema}» no declaró ninguna finalidad de tratamiento.";
        }

        if ($sinContrato->isNotEmpty()) {
            $advertencias[] = 'Encargados sin contrato al día: '.$sinContrato->implode(', ')
                .'. La ley exige contrato con cada encargado del tratamiento.';
        }

        if (($responsable = $this->responsableIncompleto()) !== null) {
            $advertencias[] = $responsable;
        }

        return $advertencias;
    }

    /**
     * Qué le falta al responsable declarado en `privacidad.responsable` para
     * que el RAT identifique a quién reclamarle. Null cuando está completo.
     *
     * Mismo criterio que ya vale para `disco_evidencia`: una clave presente
     * pero vacía en el `.env` (`PRIVACIDAD_CONTACTO=`) se ve idéntica a
     * configurada para quien solo lee el archivo; acá se comprueba el valor
     * resuelto, no si la variable existe.
     */
    private function responsableIncompleto(): ?string
    {
        $responsable = (array) config('privacidad.responsable', []);

        $campos = [
            'nombre' => 'nombre del responsable',
            'contacto' => 'contacto para ejercer derechos',
            'delegado' => 'delegado de protección de datos',
        ];

        $faltantes = collect($campos)
            ->filter(fn (string $etiqueta, string $clave): bool => blank($responsable[$clave] ?? null))
            ->values();

        if ($faltantes->isEmpty()) {
            return null;
        }

        return 'El responsable del tratamiento está incompleto: falta '.$faltantes->implode(', ')
            .'. La ley exige identificar al responsable y el contacto por el que el titular ejerce sus derechos.';
    }
}
