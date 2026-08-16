<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Consentimiento;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;

/**
 * El consentimiento solo aplica a finalidades accesorias: el registro base se
 * funda en el ejercicio de funciones legales del municipio y no se revoca, o un
 * ciudadano molesto podría borrar su propia inscripción en un registro comunal.
 */
class Consentimientos
{
    public function __construct(
        private readonly RegistroDeEvidencia $evidencia,
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

        // Igual que arriba: se resuelve ANTES de la transacción porque puede
        // rechazar, y un rechazo no tiene por qué abrir una transacción.
        $texto = $this->textoDe($opciones);

        $this->exigirRegimenDeNna($titular, $finalidad, $otorgadoPor);

        // La transacción sigue existiendo por la razón 2, no por la 1: si el registro
        // de evidencia falla después de crear la fila, todo se revierte, porque un
        // consentimiento sin evidencia contradice el propósito del módulo. Quien
        // impide dos vigentes a la vez para el mismo (titular, finalidad) es el
        // índice único sobre `vigente_clave` (ver la migración), no el orden de
        // llamadas: dos otorgar() concurrentes que ambos intenten insertar la misma
        // clave chocan en la base de datos, no en la aplicación.
        return DB::transaction(function () use ($titular, $finalidad, $medio, $opciones, $otorgadoPor, $texto) {
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
                // La fila que el adoptante MOSTRÓ, no una que se resuelva acá.
                // Ausente la opción queda null, que es el camino de los
                // consentimientos en papel anteriores a esta tabla: no tienen
                // con qué acreditar la versión y el módulo no se la inventa.
                'texto_id' => $texto?->getKey(),
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

        if ($valor instanceof Solicitante) {
            return $valor;
        }

        // Un valor que no sea un caso del enum salía como `ValueError` —o como
        // `ErrorException` si venía un arreglo—, dos fallos fuera del
        // vocabulario del módulo que ningún adoptante puede atrapar por nombre.
        // El caso realista es `'tutor'`, que el spec de diseño daba por
        // existente media docena de veces (ver Solicitante).
        if (! is_string($valor) && ! is_int($valor)) {
            throw new OpcionInvalida(
                'La opción `otorgado_por` tiene que ser un caso de Solicitante o su valor en texto; '
                .'llegó '.get_debug_type($valor).'.',
            );
        }

        return Solicitante::tryFrom((string) $valor) ?? throw new OpcionInvalida(
            "«{$valor}» no es un valor de Solicitante. Los que hay: "
            .implode(', ', array_column(Solicitante::cases(), 'value')).'.',
        );
    }

    /**
     * El texto informativo que el adoptante MOSTRÓ, resuelto sin volver a
     * consultar cuál está vigente.
     *
     * Acá vivía el defecto grave de esta clase, y conviene decirlo entero
     * porque la versión anterior parecía la natural: se aceptaba
     * `codigo_texto` y se resolvía con `Textos::vigente()` AL ESCRIBIR. Entre
     * que el formulario se renderiza y que el funcionario lo guarda cabe una
     * publicación —dos funcionarios trabajando, que es el caso que
     * `Textos::publicar()` ya contempla—, y entonces el consentimiento quedaba
     * apuntando a un texto que el titular nunca vio. Eso no es un registro
     * incompleto: es prueba falsa, y en una fiscalización pesa peor que el
     * `null` que vino a reemplazar, porque parece cumplimiento.
     *
     * Por eso se recibe la fila (o su id) y NO un código. El código no se puede
     * hacer seguro acá: para saber qué versión se mostró habría que preguntarle
     * al que la mostró, que es exactamente pasar la fila. Quien renderiza el
     * formulario ya la tiene —la sacó de `Textos::vigente()` para pintarla—, así
     * que no es trabajo nuevo para el adoptante, es dejar de tirar el dato.
     *
     * Se acepta a propósito un texto CON LA VIGENCIA YA CERRADA: es el caso
     * central de arriba —se mostró v1, se publicó v2, se guarda apuntando a
     * v1— y exigir que siga vigente reintroduciría el defecto por otra puerta.
     *
     * @param  array<string, mixed>  $opciones
     *
     * @throws OpcionInvalida si llega el código o la versión en vez de la fila
     * @throws TextoNoPublicado si la fila no existe o es de otro sistema
     */
    private function textoDe(array $opciones): ?TextoInformativo
    {
        if (isset($opciones['codigo_texto'])) {
            throw new OpcionInvalida(
                'La opción `codigo_texto` ya no se acepta: resolver el código al escribir podía dejar el '
                .'consentimiento apuntando a un texto publicado DESPUÉS de que el titular leyera el suyo. '
                .'Pasar en `texto` la fila de TextoInformativo que se mostró (o su id).',
            );
        }

        if (isset($opciones['version_texto'])) {
            throw new OpcionInvalida(
                'La opción `version_texto` ya no se acepta: era un string suelto que nada obligaba a llenar '
                .'y que no acreditaba qué leyó el titular. Pasar en `texto` la fila de TextoInformativo que '
                .'se mostró (o su id). La columna sigue en la tabla solo por las filas anteriores a ella.',
            );
        }

        $valor = $opciones['texto'] ?? null;

        if ($valor === null) {
            return null;
        }

        $texto = $valor instanceof TextoInformativo
            ? $valor
            : TextoInformativo::query()->find($valor);

        if ($texto === null) {
            throw new TextoNoPublicado(
                'La opción `texto` no corresponde a ningún texto informativo publicado: '
                .'no se puede acreditar que el titular leyó algo que no existe.',
            );
        }

        // El RAT es por sistema y `Textos::vigente()` filtra por sistema, así
        // que una fila de otro sistema solo puede llegar acá por error de
        // cableado; dejarla pasar acreditaría el consentimiento con el aviso de
        // otro registro comunal.
        if ($texto->sistema !== (string) config('privacidad.sistema')) {
            throw new TextoNoPublicado(
                "El texto #{$texto->getKey()} pertenece al sistema «{$texto->sistema}» y este es "
                .'«'.config('privacidad.sistema').'»: no acredita lo que se informó acá.',
            );
        }

        return $texto;
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
