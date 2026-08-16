<?php

namespace Muni\Shared\Privacidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Contratos\RegistroDeEvidencia;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Privacidad\Modelos\Finalidad;

/**
 * La ley pide suprimir cuando el dato ya no es necesario para la finalidad.
 * Acá eso son dos cosas distintas: los sensibles se borran de verdad y el
 * registro se anonimiza, para no perder la serie estadística comunal.
 */
class AplicarRetencion
{
    public function __construct(
        private readonly RegistroDeEvidencia $evidencia,
        private readonly ResuelveTitularesVencidos $resolvedor,
        private readonly Bitacora $bitacora,
    ) {}

    /**
     * @return array<int, array{finalidad: string, titulares: int}>
     */
    public function ejecutar(bool $simulacion = true): array
    {
        $resumen = [];

        // Las cantidades del barrido se acumulan acá y se publican UNA vez, al
        // cierre. Por titular eran un canal de correlación: «se desvincularon 4
        // filas y 1 documento», estampado en el instante exacto de la
        // anonimización, acota esa persona a un conjunto de filas huérfanas.
        // Sumadas por corrida siguen sirviendo para lo que servían —detectar que
        // el disco configurado no es donde viven los documentos— sin decir de
        // quién era cada fila.
        //
        // Lo que esto NO arregla, y hay que saberlo: en una corrida con un solo
        // titular vencido el agregado ES el dato por persona. La reducción es
        // real cuando la retención corre por lote, que es como corre por cron.
        $totales = ['titulares' => 0, 'filas' => 0, 'archivos_suprimidos' => 0, 'archivos_no_encontrados' => 0];

        $finalidades = Finalidad::query()
            ->delSistema((string) config('privacidad.sistema'))
            ->where('activa', true)
            ->whereNotNull('plazo_retencion_meses')
            ->get();

        try {
            foreach ($finalidades as $finalidad) {
                $contados = 0;

                foreach ($this->resolvedor->vencidos($finalidad) as $titular) {
                    $contados++;

                    if ($simulacion) {
                        continue;
                    }

                    $barrido = $this->aplicarA($titular, $finalidad);

                    if ($barrido === null) {
                        continue;
                    }

                    $totales['titulares']++;
                    $totales['filas'] += $barrido->filas;
                    $totales['archivos_suprimidos'] += $barrido->archivosSuprimidos;
                    $totales['archivos_no_encontrados'] += $barrido->archivosNoEncontrados;
                }

                if ($contados > 0) {
                    $resumen[] = ['finalidad' => (string) $finalidad->codigo, 'titulares' => $contados];
                }
            }
        } finally {
            // En `finally` porque cada titular va en su propia transacción: si
            // el titular número siete revienta —un documento que el disco no
            // pudo borrar, por ejemplo—, los seis anteriores ya están
            // anonimizados y sin esto se quedarían sin ninguna constancia de
            // cuánto se barrió. La excepción sigue subiendo al comando.
            if ($totales['titulares'] > 0) {
                $this->bitacora->registrarConstancia('retencion.constancia', $totales);
            }
        }

        return $resumen;
    }

    private function aplicarA(TitularDeDatos $titular, Finalidad $finalidad): ?ResultadoDesvinculacion
    {
        // El orden importa: primero se borra lo sensible, después se anonimiza.
        // Al revés, el registro anonimizado podría conservar el archivo sensible
        // sin nadie a quien asociarlo para borrarlo después.
        //
        // La transacción cubre las dos escrituras de fila y la evidencia: si
        // anonimizar() o el registro de evidencia fallan, ambas se revierten y
        // no queda un dato purgado sin bitácora que pruebe que la supresión fue
        // lícita. Lo que la transacción NO cubre son los archivos en disco que
        // purgarDatosSensibles() borra: un rollback de base de datos no
        // restaura un archivo eliminado. Por eso el orden sigue siendo
        // load-bearing incluso con la transacción: si algo falla después de
        // purgar, el archivo ya no está aunque la fila vuelva a su estado
        // anterior.
        return DB::transaction(function () use ($titular, $finalidad): ?ResultadoDesvinculacion {
            $titular->purgarDatosSensibles();
            $titular->anonimizar();

            // La entrada de evidencia se registra ANTES de desvincular, no
            // después. Registrarla después dejaría, dentro de la misma
            // transacción, una fila con titular_id vivo justo al lado de la
            // que dice titular_ref en texto plano: ids consecutivos, mismo
            // user_id, mismo instante — un join de dos saltos que en este
            // ecosistema resuelve a una identidad en el maestro de personas
            // federado, aunque el registro local ya esté anonimizado. Escrita
            // antes, esta misma entrada queda barrida por el UPDATE de
            // desvincular() de abajo: sigue existiendo, sigue diciendo
            // "retencion.aplicada", se puede seguir contando — lo único que
            // se pierde es a quién, que es exactamente el punto.
            //
            // Barrida, pero SIN referencia de grupo: esta fila nace dentro de
            // la anonimización y lleva su hora exacta, la misma que anonimizar()
            // acaba de congelar en la persona. Ponerle el titular_ref
            // encadenaría ese instante con todas las demás filas huérfanas del
            // caso. De eso se encarga la ventana de Bitacora::desvincular(); no
            // hace falta hacer nada acá, pero sí saberlo antes de mover esta
            // llamada o de agregar otra evidencia con titular en esta
            // transacción.
            //
            // El alcance de esa ventana, dicho con precisión para no repetir el
            // error de venderla como cierre: consigue que el módulo no publique
            // ningún identificador que agrupe las filas huérfanas de una
            // persona. NO consigue que el conjunto huérfano deje de ser
            // atribuible a un `personas.id`: las fechas de negocio que
            // sobreviven como hecho auditable se emparejan con
            // `personas.created_at` —que esta transacción no toca—, y el orden
            // de los ids de fila reproduce el orden de las personas
            // anonimizadas. Las dos rutas reconstruyeron 12 de 12 en el review
            // independiente. Están descritas en Bitacora::desvincular() y en el
            // pendiente 5-ter del spec, que es lo que la EIPD tiene que evaluar.
            $this->evidencia->registrar('retencion.aplicada', [
                'finalidad' => $finalidad->codigo,
                'plazo_meses' => $finalidad->plazo_retencion_meses,
            ], $titular instanceof Model ? $titular : null);

            // Un titular que no es Model no tiene morph con el que buscar sus
            // filas: no hay barrido y no hay cantidades que agregar.
            return $titular instanceof Model
                ? $this->bitacora->desvincular($titular)
                : null;
        });
    }
}
