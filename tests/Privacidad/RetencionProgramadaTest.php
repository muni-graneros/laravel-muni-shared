<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Contratos\PropagaSupresion;
use Muni\Shared\Privacidad\Contratos\ResuelveTitularesVencidos;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\SupresionSoloLocal;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

/**
 * En el sistema real el módulo estaba instalado, migrado, sembrado… y nunca
 * corría: `schedule:list` no lo listaba. Y cuando dos corridas se solaparon,
 * MariaDB tiró el error 1020 y abortó una de ellas a mitad.
 */
beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    app()->bind(PropagaSupresion::class, SupresionSoloLocal::class);
});

it('no se agenda sola: un paquete no pone a correr un destructivo en ocho sistemas', function () {
    config(['privacidad.retencion.hora' => null]);

    $comandos = collect(app(Schedule::class)->events())->map(fn (Event $e): string => (string) $e->command);

    expect($comandos->filter(fn (string $c): bool => str_contains($c, 'privacidad:aplicar-retencion')))->toBeEmpty();
});

it('se agenda con candado cuando el sistema declara la hora', function () {
    config(['privacidad.retencion.hora' => '03:30']);

    $eventos = collect(app(Schedule::class)->events())
        ->filter(fn (Event $e): bool => str_contains((string) $e->command, 'privacidad:aplicar-retencion'));

    expect($eventos)->toHaveCount(1);

    $evento = $eventos->first();

    expect($evento->expression)->toBe('30 3 * * *')
        ->and((string) $evento->command)->toContain('--ejecutar')
        // Sin esto, un cron diario que dura más de un día se solapa consigo
        // mismo y cada solape aborta una corrida a mitad.
        ->and($evento->withoutOverlapping)->toBeTrue()
        ->and($evento->onOneServer)->toBeTrue();
});

it('una corrida no arranca si ya hay otra en curso', function () {
    Finalidad::create([
        'sistema' => 'discapacidad',
        'codigo' => 'atencion',
        'nombre' => 'Atención de casos',
        'base_licitud' => BaseLicitud::FuncionLegal,
        'norma_habilitante' => 'Ley 20.422',
        'plazo_retencion_meses' => 60,
    ]);

    $vencida = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'diagnostico' => 'dato sensible de salud',
        'tratamiento_iniciado_en' => now()->subYears(6),
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    app()->bind(ResuelveTitularesVencidos::class, fn () => new class implements ResuelveTitularesVencidos
    {
        public function vencidos(Finalidad $finalidad): iterable
        {
            return PersonaDePrueba::query()->whereNotNull('documento')->get();
        }
    });

    // La corrida "que ya estaba corriendo" (el proceso que el timeout de
    // `docker compose exec` no mató).
    expect(Cache::lock('privacidad:aplicar-retencion', 60, 'otra-corrida')->get())->toBeTrue();

    $this->artisan('privacidad:aplicar-retencion --ejecutar')
        ->expectsOutputToContain('ya hay una corrida de retención en curso')
        ->assertFailed();

    expect($vencida->refresh()->nombre)->toBe('Rocío Paredes');
});

it('la simulación no toma el candado: siempre se puede mirar', function () {
    expect(Cache::lock('privacidad:aplicar-retencion', 60, 'otra-corrida')->get())->toBeTrue();

    $this->artisan('privacidad:aplicar-retencion')->assertSuccessful();
});

it('el candado se suelta al terminar la corrida', function () {
    $this->artisan('privacidad:aplicar-retencion --ejecutar')->assertSuccessful();

    expect(Cache::lock('privacidad:aplicar-retencion', 60, 'la-siguiente')->get())->toBeTrue();
});
