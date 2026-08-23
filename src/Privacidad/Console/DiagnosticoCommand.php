<?php

namespace Muni\Shared\Privacidad\Console;

use Illuminate\Console\Command;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\NingunTitularVencido;
use Throwable;

/**
 * Qué le falta a ESTE sistema para que el módulo haga lo que promete.
 *
 * Existe por una razón concreta: **casi todo lo que este módulo hace mal, lo
 * hace en silencio.** Cada línea de abajo es un defecto que ya ocurrió al
 * adoptarlo en el ecosistema municipal, no una precaución imaginada:
 *
 * - Sin `PRIVACIDAD_SISTEMA`, `AplicarRetencion` no encuentra ninguna finalidad
 *   y no purga nada. Estuvo así en una suite entera y en CI sin que nadie lo
 *   notara: los tests pasaban porque no había nada que borrar.
 * - `ResuelveTitularesVencidos` viene enlazado a `NingunTitularVencido`, que
 *   devuelve una lista vacía siempre. Es el fallo seguro correcto para un
 *   sistema recién instalado —mejor no purgar que purgar de más—, pero el
 *   adoptante que nunca lo sustituya tiene un sistema que **jamás borra a
 *   nadie** y que no se queja.
 * - `PRIVACIDAD_DISCO_EVIDENCIA` mal puesto deja los documentos del expediente
 *   vivos en disco después de anonimizar.
 *
 * Lo que este comando **no** hace: arreglar nada, ni afirmar que el sistema
 * cumple la ley. Comprueba que las piezas técnicas estén enchufadas. Que el RAT
 * diga la verdad, que los plazos sean los correctos y que la base de licitud
 * corresponda son decisiones jurídicas que ningún comando puede verificar.
 */
class DiagnosticoCommand extends Command
{
    protected $signature = 'privacidad:diagnostico';

    protected $description = 'Revisa qué le falta a este sistema para que el módulo de privacidad funcione de verdad';

    public function handle(): int
    {
        $hallazgos = array_merge(
            $this->revisarIdentidadDelSistema(),
            $this->revisarResponsable(),
            $this->revisarContratos(),
            $this->revisarAlmacenamiento(),
        );

        if ($hallazgos === []) {
            $this->info('Las piezas del módulo están enchufadas.');
            $this->line('');
            $this->comment('Lo que esto NO dice: que el RAT sea correcto. Qué trata el sistema, con qué');
            $this->comment('base legal y por cuánto tiempo son afirmaciones jurídicas que no se pueden');
            $this->comment('comprobar desde acá. Ver `privacidad:rat`.');

            return self::SUCCESS;
        }

        $this->error(count($hallazgos) === 1
            ? 'Falta 1 cosa para que el módulo funcione:'
            : 'Faltan '.count($hallazgos).' cosas para que el módulo funcione:');
        $this->line('');

        foreach ($hallazgos as $i => $hallazgo) {
            $this->line(sprintf('  %d. %s', $i + 1, $hallazgo['titulo']));
            $this->line('     '.$hallazgo['consecuencia']);
            $this->line('     → '.$hallazgo['arreglo']);
            $this->line('');
        }

        return self::FAILURE;
    }

    /** @return list<array{titulo: string, consecuencia: string, arreglo: string}> */
    private function revisarIdentidadDelSistema(): array
    {
        $sistema = (string) config('privacidad.sistema');

        if ($sistema === '') {
            return [[
                'titulo' => 'PRIVACIDAD_SISTEMA no está definido.',
                'consecuencia' => 'Sin él, ninguna finalidad se encuentra: el RAT sale vacío y la retención no purga a nadie, en silencio.',
                'arreglo' => 'Definir PRIVACIDAD_SISTEMA en el .env con un identificador estable de este sistema.',
            ]];
        }

        // Se pregunta por la tabla dentro de un try: en una instalación recién
        // hecha las migraciones pueden no haber corrido todavía, y el
        // diagnóstico tiene que poder decirlo en vez de reventar.
        try {
            $finalidades = Finalidad::query()->delSistema($sistema)->count();
        } catch (Throwable $e) {
            return [[
                'titulo' => 'Las tablas del módulo no existen.',
                'consecuencia' => 'No se pudo consultar las finalidades: '.$e->getMessage(),
                'arreglo' => 'Correr `php artisan migrate`.',
            ]];
        }

        if ($finalidades === 0) {
            return [[
                'titulo' => "El sistema «{$sistema}» no declaró ninguna finalidad.",
                'consecuencia' => 'El RAT sale vacío ante una fiscalización y la retención no tiene con qué decidir.',
                'arreglo' => 'Sembrar las finalidades con su base de licitud, su norma y su plazo. Es una declaración jurídica: la escribe el municipio, no el código.',
            ]];
        }

        return [];
    }

    /** @return list<array{titulo: string, consecuencia: string, arreglo: string}> */
    private function revisarResponsable(): array
    {
        $faltantes = collect([
            'nombre' => 'PRIVACIDAD_RESPONSABLE',
            'contacto' => 'PRIVACIDAD_CONTACTO',
            'delegado' => 'PRIVACIDAD_DELEGADO',
        ])->filter(fn (string $var, string $clave): bool => blank(config("privacidad.responsable.{$clave}")));

        if ($faltantes->isEmpty()) {
            return [];
        }

        return [[
            'titulo' => 'El responsable del tratamiento está incompleto: falta '.$faltantes->values()->implode(', ').'.',
            'consecuencia' => 'El expediente que se le entrega al titular sale con el contacto en blanco: el vecino recibe un documento que no le dice a quién dirigirse para ejercer sus derechos.',
            'arreglo' => 'Definir esas variables en el .env. Qué correo y qué persona van ahí lo decide el municipio.',
        ]];
    }

    /** @return list<array{titulo: string, consecuencia: string, arreglo: string}> */
    private function revisarContratos(): array
    {
        $hallazgos = [];

        // Se compara la instancia RESUELTA, no si hay un binding: enlazar a mano
        // el mismo default no cambia nada, y el adoptante que lo hiciera se
        // quedaría creyendo que ya implementó algo.
        if (app(ResuelveTitularesVencidos::class) instanceof NingunTitularVencido) {
            $hallazgos[] = [
                'titulo' => 'ResuelveTitularesVencidos sigue en NingunTitularVencido.',
                'consecuencia' => 'Es el enlace por defecto y devuelve una lista vacía siempre: este sistema NUNCA va a purgar a nadie, y no lo va a avisar.',
                'arreglo' => 'Implementar el contrato con la «última señal de vida» propia del dominio y enlazarlo en un ServiceProvider.',
            ];
        }

        // `PropagaSupresion` NO tiene enlace por defecto a propósito: sin
        // declaración, `AplicarRetencion` se niega a ejecutar. Eso es correcto
        // —es fallo cerrado— pero desde afuera parece que el comando «no hace
        // nada», y conviene nombrarlo antes de que alguien lo persiga.
        try {
            app(PropagaSupresion::class);
        } catch (Throwable) {
            $hallazgos[] = [
                'titulo' => 'PropagaSupresion no está declarado.',
                'consecuencia' => 'La retención se niega a ejecutar mientras falte, que es lo correcto: destruir local sin saber qué pasa en el registro federado sería peor.',
                'arreglo' => 'Enlazar una implementación que propague la baja, o SupresionSoloLocal si este sistema no es modelo de lectura de ningún maestro. Declararlo, no omitirlo.',
            ];
        }

        return $hallazgos;
    }

    /** @return list<array{titulo: string, consecuencia: string, arreglo: string}> */
    private function revisarAlmacenamiento(): array
    {
        $disco = config('privacidad.disco_evidencia');

        if (blank($disco)) {
            return [[
                'titulo' => 'PRIVACIDAD_DISCO_EVIDENCIA no está definido.',
                'consecuencia' => 'Es el disco donde viven los documentos del expediente. Sin él, la purga no sabe de dónde borrar y los archivos sobreviven a la anonimización.',
                'arreglo' => 'Definirlo en el .env apuntando a un disco declarado en config/filesystems.php.',
            ]];
        }

        if (! array_key_exists((string) $disco, (array) config('filesystems.disks', []))) {
            return [[
                'titulo' => "El disco de evidencia «{$disco}» no existe en config/filesystems.php.",
                'consecuencia' => 'Cualquier operación sobre los documentos del expediente va a fallar al resolver el disco.',
                'arreglo' => 'Declarar ese disco, o apuntar PRIVACIDAD_DISCO_EVIDENCIA a uno que exista.',
            ]];
        }

        return [];
    }
}
