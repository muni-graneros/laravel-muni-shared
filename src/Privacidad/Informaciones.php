<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\InformacionEntregada;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;

/**
 * El módulo no muestra nada: cada sistema renderiza el texto en su formulario y
 * llama acá para sellar que lo mostró. Lo que el módulo aporta es el texto
 * vigente y la prueba de la entrega.
 */
class Informaciones
{
    public function __construct(
        private readonly Textos $textos,
        private readonly RegistroDeEvidencia $evidencia,
    ) {}

    /**
     * Sella que se le mostró al titular el texto de ese código.
     *
     * `$opciones['texto']` es la fila (o el id) que el sistema REALMENTE
     * renderizó, y conviene pasarla: sin ella, el texto se resuelve acá, al
     * escribir, y entre el render del formulario y el guardado cabe una
     * publicación nueva —el mismo defecto que se corrigió en
     * `Consentimientos::otorgar()`—, con lo que la constancia diría que se
     * informó una versión que el titular no vio.
     *
     * Sin la opción se conserva el camino por código, que no es equivalente:
     * es una comodidad para el uso donde mostrar y sellar ocurren en la misma
     * petición, y ahí la ventana no existe.
     *
     * @param  array<string, mixed>  $opciones
     *
     * @throws OpcionInvalida si el texto pasado no es el de ese código
     * @throws TextoNoPublicado si no hay texto vigente con ese código
     */
    public function registrar(
        Model $titular,
        string $codigo,
        MedioDeConsentimiento $medio,
        array $opciones = [],
    ): InformacionEntregada {
        $texto = isset($opciones['texto'])
            ? $this->textoMostrado($opciones['texto'], $codigo)
            : $this->textos->vigente($codigo);

        if ($texto === null) {
            throw new TextoNoPublicado(
                "No hay un texto vigente con código «{$codigo}» en este sistema: "
                .'no se puede acreditar que se informó algo que no está publicado.',
            );
        }

        return DB::transaction(function () use ($titular, $texto, $codigo, $medio, $opciones): InformacionEntregada {
            $registro = InformacionEntregada::create([
                'sistema' => (string) config('privacidad.sistema'),
                'titular_type' => $titular->getMorphClass(),
                'titular_id' => $titular->getKey(),
                'texto_id' => $texto->getKey(),
                'entregado_en' => now(),
                'medio' => $medio,
                'user_id' => Auth::id(),
                'ip_hash' => isset($opciones['ip']) ? hash('sha256', (string) $opciones['ip']) : null,
            ]);

            // Solo el código y la versión: volcar el contenido duplicaría en la
            // bitácora un texto que ya vive, íntegro y con su hash, en privacidad_textos.
            $this->evidencia->registrar('informacion.entregada', [
                'codigo' => $codigo,
                'version' => $texto->version,
            ], $titular);

            return $registro;
        });
    }

    /**
     * La fila que el adoptante dice haber mostrado, comprobada contra lo que
     * dice haber informado.
     *
     * Se acepta con la vigencia ya cerrada a propósito —es el caso central: se
     * mostró v1 y entretanto se publicó v2—, pero NO se acepta que el código no
     * calce: un texto de otro código sellaría «se informó el aviso de
     * recolección» adjuntando el de cámaras, y esa fila la lee después quien
     * fiscaliza. El sistema tampoco, por la misma razón.
     */
    private function textoMostrado(mixed $valor, string $codigo): TextoInformativo
    {
        $texto = $valor instanceof TextoInformativo ? $valor : TextoInformativo::query()->find($valor);

        if ($texto === null) {
            throw new TextoNoPublicado(
                'La opción `texto` no corresponde a ningún texto informativo publicado: '
                .'no se puede acreditar que se informó algo que no existe.',
            );
        }

        if ($texto->codigo !== $codigo || $texto->sistema !== (string) config('privacidad.sistema')) {
            throw new OpcionInvalida(
                "El texto #{$texto->getKey()} es «{$texto->sistema}/{$texto->codigo}» y se está registrando "
                ."la entrega de «{$codigo}» en este sistema: uno de los dos está mal cableado.",
            );
        }

        return $texto;
    }

    public function seInformo(Model $titular, string $codigo): bool
    {
        return InformacionEntregada::query()
            ->where('titular_type', $titular->getMorphClass())
            ->where('titular_id', $titular->getKey())
            ->whereHas('texto', fn ($q) => $q->where('codigo', $codigo))
            ->exists();
    }
}
