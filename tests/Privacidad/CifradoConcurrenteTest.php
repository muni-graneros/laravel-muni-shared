<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Muni\Shared\Privacidad\CifradoCast;
use Muni\Shared\Privacidad\Console\CifrarTextoLibreCommand;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

/**
 * `privacidad:cifrar-texto-libre --ejecutar` lee de a 500 filas y después
 * reescribe fila por fila. Entre esa lectura y el UPDATE el sistema sigue
 * atendiendo: si alguien guarda un valor nuevo en una de esas columnas, el
 * UPDATE no puede pisarlo con el texto viejo cifrado.
 *
 * La escritura concurrente se simula de forma determinista: se engancha al
 * evento de la consulta de LECTURA del comando (que se dispara después de
 * leer el lote y antes de procesarlo) y ahí se escribe la fila, como lo haría
 * otra petición del sistema.
 */
function solicitudEnClaro(PersonaDePrueba $titular, string $detalle, ?string $fundamento): int
{
    return DB::table('privacidad_solicitudes')->insertGetId([
        'sistema' => 'discapacidad',
        'titular_type' => $titular->getMorphClass(),
        'titular_id' => $titular->getKey(),
        'tipo' => 'acceso',
        'estado' => 'acogida',
        'recibida_en' => now(),
        'vence_en' => now()->addDays(30),
        'detalle' => $detalle,
        'fundamento_resolucion' => $fundamento,
        'verificacion_identidad' => json_encode(['metodo' => 'cedula_presencial', 'evidencia' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Escribe `$cambio` en la fila `$id` justo después de que el comando lea el lote.
 *
 * @param  array<string, mixed>  $cambio
 */
function escribirTrasLaLectura(int $id, array $cambio): void
{
    $hecho = false;

    Event::listen(QueryExecuted::class, function (QueryExecuted $consulta) use ($id, $cambio, &$hecho): void {
        if ($hecho
            || ! str_starts_with(strtolower(ltrim($consulta->sql)), 'select')
            || ! str_contains($consulta->sql, 'privacidad_solicitudes')
            || ! str_contains($consulta->sql, 'fundamento_resolucion')) {
            return;
        }

        $hecho = true;
        DB::table('privacidad_solicitudes')->where('id', $id)->update($cambio);
    });
}

function columnaCruda(int $id, string $columna): ?string
{
    return DB::table('privacidad_solicitudes')->where('id', $id)->value($columna);
}

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);
});

it('no pisa un valor que el sistema guardó entre la lectura y el UPDATE, y lo informa', function () {
    $tocadaId = solicitudEnClaro($this->titular, 'Detalle viejo en claro', 'Fundamento viejo en claro');
    $quietaId = solicitudEnClaro($this->titular, 'Otra fila vieja', null);

    escribirTrasLaLectura($tocadaId, ['detalle' => CifradoCast::cifrar('Detalle NUEVO que guardó el sistema')]);

    $salida = Artisan::call('privacidad:cifrar-texto-libre', ['--ejecutar' => true]);
    $texto = Artisan::output();

    expect($salida)->toBe(0)
        // El valor nuevo sobrevive: el UPDATE del comando no lo reemplazó.
        ->and(Solicitud::findOrFail($tocadaId)->detalle)->toBe('Detalle NUEVO que guardó el sistema')
        // La fila saltada se dejó entera: su otra columna sigue en claro para la próxima corrida.
        ->and(columnaCruda($tocadaId, 'fundamento_resolucion'))->toBe('Fundamento viejo en claro')
        // La fila que nadie tocó sí quedó cifrada.
        ->and(CifradoCast::estaCifrado(columnaCruda($quietaId, 'detalle')))->toBeTrue()
        ->and(Solicitud::findOrFail($quietaId)->detalle)->toBe('Otra fila vieja')
        // La salida cuenta lo cifrado y lo saltado…
        ->and($texto)->toContain('privacidad_solicitudes: 2 fila(s) con texto libre en claro, 1 cifrada(s).')
        ->and($texto)->toContain('privacidad_solicitudes: 1 fila(s) cambiaron durante la corrida')
        // …sin mostrar el contenido de las columnas (datos personales).
        ->and($texto)->not->toContain('Detalle viejo')
        ->and($texto)->not->toContain('Fundamento viejo')
        ->and($texto)->not->toContain('NUEVO');

    // Una corrida posterior termina el trabajo sin tocar el valor nuevo.
    Event::forget(QueryExecuted::class);
    Artisan::call('privacidad:cifrar-texto-libre', ['--ejecutar' => true]);

    expect(Artisan::output())->toContain('privacidad_solicitudes: 1 fila(s) con texto libre en claro, 1 cifrada(s).')
        ->and(CifradoCast::estaCifrado(columnaCruda($tocadaId, 'fundamento_resolucion')))->toBeTrue()
        ->and(Solicitud::findOrFail($tocadaId)->fundamento_resolucion)->toBe('Fundamento viejo en claro')
        ->and(Solicitud::findOrFail($tocadaId)->detalle)->toBe('Detalle NUEVO que guardó el sistema');
});

it('una columna que estaba vacía y el sistema llenó entretanto tampoco se pisa', function () {
    $id = solicitudEnClaro($this->titular, 'Detalle viejo en claro', null);

    escribirTrasLaLectura($id, ['fundamento_resolucion' => CifradoCast::cifrar('Fundamento NUEVO')]);

    Artisan::call('privacidad:cifrar-texto-libre', ['--ejecutar' => true]);

    expect(Solicitud::findOrFail($id)->fundamento_resolucion)->toBe('Fundamento NUEVO')
        ->and(Solicitud::findOrFail($id)->detalle)->toBe('Detalle viejo en claro')
        ->and(CifradoCast::estaCifrado(columnaCruda($id, 'detalle')))->toBeTrue();
});

it('con otra corrida en curso no ejecuta y lo dice', function () {
    $id = solicitudEnClaro($this->titular, 'Detalle viejo en claro', null);

    expect(Cache::lock(CifrarTextoLibreCommand::CANDADO, 60, 'otra-corrida')->get())->toBeTrue();

    $this->artisan('privacidad:cifrar-texto-libre --ejecutar')
        ->expectsOutputToContain('ya hay otra corrida de privacidad:cifrar-texto-libre --ejecutar en curso')
        ->assertFailed();

    expect(columnaCruda($id, 'detalle'))->toBe('Detalle viejo en claro');
});

it('la simulación no toma el candado: siempre se puede mirar', function () {
    solicitudEnClaro($this->titular, 'Detalle viejo en claro', null);

    expect(Cache::lock(CifrarTextoLibreCommand::CANDADO, 60, 'otra-corrida')->get())->toBeTrue();

    $this->artisan('privacidad:cifrar-texto-libre')->assertSuccessful();
});

it('el candado se suelta al terminar la corrida', function () {
    solicitudEnClaro($this->titular, 'Detalle viejo en claro', null);

    $this->artisan('privacidad:cifrar-texto-libre --ejecutar')->assertSuccessful();

    expect(Cache::lock(CifrarTextoLibreCommand::CANDADO, 60, 'la-siguiente')->get())->toBeTrue();
});
