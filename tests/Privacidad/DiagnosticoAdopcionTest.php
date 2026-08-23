<?php

use Illuminate\Support\Facades\Artisan;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\NingunTitularVencido;
use Muni\Shared\Privacidad\SupresionSoloLocal;

/**
 * El diagnóstico existe porque **todo lo que este módulo hace mal, lo hace en
 * silencio**, y esa fue la lección más repetida al adoptarlo en el ecosistema:
 *
 * - Sin `PRIVACIDAD_SISTEMA`, la retención era un no-op en toda una suite y en
 *   CI sin que nadie lo notara.
 * - `ResuelveTitularesVencidos` viene enlazado a `NingunTitularVencido`, que
 *   no purga a nadie: es el fallo seguro, pero un adoptante que nunca lo
 *   sustituya tiene un sistema que jamás borra y no lo dice.
 * - En media docena de repos faltaba `config/services.php` y Laravel devolvía
 *   null sin chistar, así que el write-through quedaba mudo.
 *
 * Un comando que enumere lo que falta convierte todo eso en algo que se lee en
 * diez segundos. No arregla nada por su cuenta: informa.
 */
beforeEach(function () {
    putenv('COLUMNS=200');

    config([
        'privacidad.sistema' => '',
        'privacidad.responsable.nombre' => null,
        'privacidad.responsable.contacto' => null,
        'privacidad.responsable.delegado' => null,
        'privacidad.disco_evidencia' => null,
    ]);
});

it('un sistema recién instalado sale reprobado y dice exactamente qué le falta', function (): void {
    $salida = Artisan::call('privacidad:diagnostico');
    $texto = Artisan::output();

    // Código de salida distinto de cero: un script de despliegue puede
    // comprobarlo sin parsear el texto.
    expect($salida)->not->toBe(0);

    expect($texto)
        ->toContain('PRIVACIDAD_SISTEMA')
        ->toContain('ninguna finalidad')
        ->toContain('NingunTitularVencido');
});

it('nombra el contrato de supresión cuando no está declarado, porque sin él la retención se niega a correr', function (): void {
    config(['privacidad.sistema' => 'demo']);

    Artisan::call('privacidad:diagnostico');

    expect(Artisan::output())->toContain('PropagaSupresion');
});

it('deja de reclamar lo que el adoptante ya resolvió', function (): void {
    config([
        'privacidad.sistema' => 'demo',
        'privacidad.responsable.nombre' => 'Municipalidad de Prueba',
        'privacidad.responsable.contacto' => 'privacidad@example.cl',
        'privacidad.responsable.delegado' => 'Delegada de Prueba',
        'privacidad.disco_evidencia' => 'local',
    ]);

    Finalidad::create([
        'sistema' => 'demo',
        'codigo' => 'registro',
        'nombre' => 'Registro',
        'descripcion' => 'Registro de prueba',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley de prueba, art. 1',
        'plazo_retencion_meses' => 12,
        'activa' => true,
    ]);

    app()->bind(ResuelveTitularesVencidos::class, fn () => new class implements ResuelveTitularesVencidos
    {
        public function vencidos(Finalidad $finalidad): iterable
        {
            return [];
        }
    });
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);

    $salida = Artisan::call('privacidad:diagnostico');
    $texto = Artisan::output();

    expect($salida)->toBe(0)
        ->and($texto)->not->toContain('PRIVACIDAD_SISTEMA')
        ->and($texto)->not->toContain('NingunTitularVencido')
        ->and($texto)->not->toContain('PropagaSupresion');
});

it('distingue el resolvedor por defecto de uno propio, que es lo que separa purgar de no purgar', function (): void {
    config(['privacidad.sistema' => 'demo']);

    // Enlace explícito al MISMO default: sigue sin purgar, así que sigue siendo
    // un hallazgo. Que alguien lo escriba a mano no lo vuelve una implementación.
    app()->bind(ResuelveTitularesVencidos::class, NingunTitularVencido::class);

    Artisan::call('privacidad:diagnostico');

    expect(Artisan::output())->toContain('NingunTitularVencido');
});
