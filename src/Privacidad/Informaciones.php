<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Modelos\InformacionEntregada;

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

    /** @param array<string, mixed> $opciones */
    public function registrar(
        Model $titular,
        string $codigo,
        MedioDeConsentimiento $medio,
        array $opciones = [],
    ): InformacionEntregada {
        $texto = $this->textos->vigente($codigo);

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

    public function seInformo(Model $titular, string $codigo): bool
    {
        return InformacionEntregada::query()
            ->where('titular_type', $titular->getMorphClass())
            ->where('titular_id', $titular->getKey())
            ->whereHas('texto', fn ($q) => $q->where('codigo', $codigo))
            ->exists();
    }
}
