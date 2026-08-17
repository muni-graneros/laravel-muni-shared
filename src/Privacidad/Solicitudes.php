<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Solicitud;

class Solicitudes
{
    public function __construct(
        private readonly RegistroDeEvidencia $evidencia,
        private readonly Bloqueos $bloqueos,
        private readonly Edades $edades,
    ) {}

    /**
     * Recibe una solicitud ARCOP y hace correr el plazo legal de respuesta.
     *
     * El régimen de edad es el MISMO que el del consentimiento, y que no lo
     * fuera era el defecto: un niño de 10 años no podía consentir que le
     * publicaran una foto y sí podía llevarse la copia íntegra de su registro
     * de discapacidad —datos de salud—, que le acogieran una supresión que lo
     * borra de un registro comunal del que su familia puede depender, o una
     * rectificación que se propaga al maestro federado de personas. La
     * asimetría no la había decidido nadie: `Solicitudes` simplemente nunca
     * consultó `Edades`.
     *
     * NINGUNO de los cinco derechos se exceptúa, y la decisión es deliberada,
     * no por omisión: el módulo no distingue derechos que el NNA pueda ejercer
     * personalmente porque no tiene con qué hacerlo bien —la frontera fina
     * (edad del adolescente, tipo de dato, interés superior) exige una lectura
     * de la ley y su reglamento que este paquete no puede zanjar por los ocho
     * sistemas que lo adoptan—. Lo que se elige es el lado que no entrega
     * datos de salud de un menor a quien se presenta solo. La consecuencia
     * —que un adolescente no pueda pedir su propia copia sin su representante—
     * está anotada como pendiente en
     * `docs/superpowers/specs/2026-08-13-ley-21719-pendientes.md`.
     *
     * Qué cubre esta comprobación, exactamente: el camino de este método.
     * Un adoptante que cree filas de `privacidad_solicitudes` a mano se la
     * salta, igual que en el consentimiento.
     *
     * @param  ?string  $acreditacionPath  ruta del documento que acredita la
     *                                     representación; obligatorio cuando no
     *                                     actúa el propio titular
     *
     * @throws IdentidadNoVerificada si no se acreditó quién está pidiendo
     * @throws RepresentacionNoAcreditada si actúa un tercero sin documento
     * @throws EdadNoAcreditada si el sistema no sabe si el titular es NNA
     * @throws RepresentacionRequerida si un NNA pretende ejercer solo
     *
     * Las cuatro implementan `SolicitudRechazada`: un llamador que solo
     * necesita mostrar "el módulo rechazó esto, y esto dice por qué" puede
     * atraparlas todas con un único `catch (SolicitudRechazada $e)` en vez de
     * los cuatro `catch` por nombre.
     */
    public function registrar(
        Model $titular,
        TipoDeSolicitud $tipo,
        string $detalle,
        ResultadoVerificacion $verificacion,
        Solicitante $solicitante = Solicitante::Titular,
        ?string $acreditacionPath = null,
    ): Solicitud {
        // Entregar datos personales a quien no acreditó ser el titular es la
        // fuga más fácil de cometer y la más difícil de explicar después.
        //
        // Va primero a propósito: si faltan varias cosas, lo que el funcionario
        // tiene que resolver antes que nada es quién es el que está al otro
        // lado del mesón.
        if (! $verificacion->verificado) {
            throw new IdentidadNoVerificada(
                'No se puede registrar la solicitud: la identidad del solicitante no fue verificada ('
                .($verificacion->evidencia['motivo'] ?? 'sin motivo').').',
            );
        }

        // Acreditar la identidad NO es acreditar la representación: lo primero
        // dice quién es el que vino, lo segundo que puede actuar por otro. Esta
        // tabla tenía lo primero desde el principio y nada de lo segundo.
        $acreditacion = $this->acreditacionDe($acreditacionPath, $solicitante);

        $this->exigirRegimenDeEdad($titular, $solicitante);

        // Como en el resto del módulo: la fila no puede sobrevivir sin su
        // entrada de bitácora. Acá pesa doble, porque la recepción de una
        // solicitud ARCOP es lo que hace correr el plazo legal de respuesta:
        // una solicitud registrada sin evidencia de cuándo entró es exactamente
        // lo que se discute en una fiscalización.
        return DB::transaction(function () use ($titular, $tipo, $detalle, $verificacion, $solicitante, $acreditacion) {
            $solicitud = Solicitud::create([
                'sistema' => (string) config('privacidad.sistema'),
                'titular_type' => $titular->getMorphClass(),
                'titular_id' => $titular->getKey(),
                'tipo' => $tipo,
                'estado' => EstadoDeSolicitud::Recibida,
                'recibida_en' => now(),
                'vence_en' => now()->addDays((int) config('privacidad.plazo_respuesta_dias')),
                'detalle' => $detalle,
                'verificacion_identidad' => [
                    'metodo' => $verificacion->metodo,
                    'evidencia' => $verificacion->evidencia,
                ],
                'solicitante' => $solicitante,
                // El documento que acredita que quien vino puede actuar por el
                // titular. Null solo cuando actúa el propio titular.
                'acreditacion_path' => $acreditacion,
                'user_registro_id' => Auth::id(),
            ]);

            $this->evidencia->registrar('solicitud.registrada', [
                'solicitud_id' => $solicitud->getKey(),
                'tipo' => $tipo->value,
            ], $titular);

            // Solo rectificación y oposición: un acceso o una portabilidad no
            // ponen nada en disputa, y bloquear por ellas frenaría la atención
            // sin ninguna razón legal.
            $disputa = in_array($tipo, [TipoDeSolicitud::Rectificacion, TipoDeSolicitud::Oposicion], true);

            if ($disputa && config('privacidad.bloquear_durante_solicitud')) {
                $this->bloqueos->bloquear($titular, null, "Solicitud de {$tipo->etiqueta()} en trámite", $solicitud);
            }

            return $solicitud;
        });
    }

    public function tomar(Solicitud $solicitud): void
    {
        $this->exigirPendiente($solicitud);

        $solicitud->update(['estado' => EstadoDeSolicitud::EnTramite]);
    }

    public function acoger(Solicitud $solicitud, string $fundamento, ?string $respuestaPath = null): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::Acogida, $fundamento, $respuestaPath);
    }

    public function acogerParcialmente(Solicitud $solicitud, string $fundamento, ?string $respuestaPath = null): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::AcogidaParcial, $fundamento, $respuestaPath);
    }

    public function rechazar(Solicitud $solicitud, string $fundamento): void
    {
        $this->resolver($solicitud, EstadoDeSolicitud::Rechazada, $fundamento);
    }

    private function resolver(
        Solicitud $solicitud,
        EstadoDeSolicitud $estado,
        string $fundamento,
        ?string $respuestaPath = null,
    ): void {
        $this->exigirPendiente($solicitud);

        if (trim($fundamento) === '') {
            throw new ResolucionInvalida('Toda resolución debe ir fundada: es lo que se le responde al titular.');
        }

        // La resolución es la respuesta oficial al titular: si el registro de
        // evidencia falla, la solicitud tampoco puede quedar sellada, o el
        // sistema mostraría una solicitud resuelta sin rastro de quién la
        // resolvió ni cuándo. Rectificaciones llama a acoger() desde su propia
        // transacción; Laravel convierte esta en un savepoint y el rollback
        // externo sigue arrastrándola.
        DB::transaction(function () use ($solicitud, $estado, $fundamento, $respuestaPath): void {
            $solicitud->update([
                'estado' => $estado,
                'resuelta_en' => now(),
                'fundamento_resolucion' => $fundamento,
                'respuesta_path' => $respuestaPath,
                'user_resolucion_id' => Auth::id(),
            ]);

            $this->evidencia->registrar("solicitud.{$estado->value}", [
                'solicitud_id' => $solicitud->getKey(),
                'tipo' => $solicitud->tipo->value,
            ], $solicitud->titular);

            // Se resuelva como se resuelva —acogida, rechazada— la disputa
            // terminó: el bloqueo que la registró no tiene ya ninguna razón
            // para seguir vigente. No hace nada si nunca se bloqueó (config
            // apagada, o un tipo que no dispara bloqueo).
            $this->bloqueos->levantarPorSolicitud($solicitud);
        });
    }

    /**
     * La ruta del documento que acredita la representación, exigida cuando no
     * actúa el titular.
     *
     * Igual que en `Consentimientos`: lo que garantiza es que exista una ruta
     * no vacía, no que el archivo exista ni que diga lo que dice ser. Lo que
     * cierra es registrar «vino su representante legal» sin ningún documento
     * detrás.
     *
     * @throws RepresentacionNoAcreditada
     */
    private function acreditacionDe(?string $ruta, Solicitante $solicitante): ?string
    {
        $ruta = trim((string) $ruta);

        if (! $solicitante->exigeAcreditarRepresentacion()) {
            return $ruta === '' ? null : $ruta;
        }

        if ($ruta === '') {
            throw new RepresentacionNoAcreditada(
                "La solicitud la presenta «{$solicitante->etiqueta()}» y no se acompañó el documento que "
                .'acredita la representación. Adjuntarlo y pasar su ruta: entregar o borrar los datos de '
                .'una persona a pedido de un tercero sin ese papel es la fuga que este módulo evita.',
            );
        }

        return $ruta;
    }

    /**
     * El mismo régimen reforzado que aplica el consentimiento.
     *
     * Solo corre para quien implementa `TitularDeDatos`: a los demás no se les
     * puede preguntar la fecha de nacimiento ni exigir que la tengan.
     *
     * @throws EdadNoAcreditada
     * @throws RepresentacionRequerida
     */
    private function exigirRegimenDeEdad(Model $titular, Solicitante $solicitante): void
    {
        if (! $titular instanceof TitularDeDatos) {
            return;
        }

        $esNNA = $this->edades->esNNA($titular);

        if ($esNNA === null) {
            throw new EdadNoAcreditada(
                'No se puede tramitar la solicitud sin saber si el titular es mayor de edad: este sistema no '
                .'tiene su fecha de nacimiento. Acreditarla con el documento correspondiente y reintentar.',
            );
        }

        if (! $esNNA) {
            return;
        }

        // Apoderado tampoco: un menor no puede otorgar mandato, así que un
        // apoderado suyo no existe jurídicamente. Mismo criterio, misma
        // excepción y mismo mensaje que en el consentimiento.
        if ($solicitante !== Solicitante::RepresentanteLegal) {
            throw new RepresentacionRequerida(
                'Los derechos de un menor de edad los ejerce su representante legal, no él mismo ni un '
                .'apoderado suyo: un menor no puede otorgar mandato. Registrar la solicitud con el '
                .'representante legal y el documento que acredite serlo.',
            );
        }
    }

    private function exigirPendiente(Solicitud $solicitud): void
    {
        if ($solicitud->estado->estaResuelta()) {
            throw new ResolucionInvalida(
                "La solicitud #{$solicitud->getKey()} ya fue resuelta el "
                .$solicitud->resuelta_en?->format('d-m-Y').'. Reabrirla falsearía el registro.',
            );
        }
    }
}
