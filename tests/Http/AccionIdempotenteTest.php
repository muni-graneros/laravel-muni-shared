<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Muni\Shared\Http\Middleware\AccionIdempotente;

/*
 * Vector cubierto: reintento de un POST cuya respuesta se perdió (doble
 * ejecución), IDOR (la clave de otro usuario no devuelve su respuesta) y
 * carrera (dos reintentos simultáneos).
 */
beforeEach(function () {
    $this->ejecuciones = 0;
    $contador = &$this->ejecuciones;

    Route::post('/accion', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador], 201);
    })->middleware(AccionIdempotente::class);

    Route::post('/otra', fn () => response()->json(['otra' => true]))->middleware(AccionIdempotente::class);
    Route::post('/falla', fn () => response()->json(['error' => 'x'], 422))->middleware(AccionIdempotente::class);

    $this->usuario = new User;
    $this->usuario->id = 7;
    $this->actingAs($this->usuario);

    $this->clave = '7d1f5c0e-2b1a-4f3e-9a55-0c6d8e7b1a42';
});

it('dos POST con la misma clave ejecutan una vez y devuelven la misma respuesta', function () {
    $primera = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();
    $segunda = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    expect($this->ejecuciones)->toBe(1)
        ->and($segunda->json())->toEqual($primera->json())
        ->and($segunda->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($primera->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('la clave de otro usuario no devuelve su respuesta: se ejecuta aparte', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    $otro = new User;
    $otro->id = 8;
    $this->actingAs($otro);

    $respuesta = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    expect($this->ejecuciones)->toBe(2)
        ->and($respuesta->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('una clave que no es un UUID da 422 y no ejecuta nada', function (string $invalida) {
    $this->withHeader('Idempotency-Key', $invalida)->postJson('/accion')->assertStatus(422);

    expect($this->ejecuciones)->toBe(0);
})->with(['texto' => 'hola', 'casi' => '7d1f5c0e-2b1a-4f3e-9a55', 'inyección' => "x'; DROP TABLE users;--"]);

it('sin clave se comporta como siempre: cada POST ejecuta', function () {
    $this->postJson('/accion')->assertCreated();
    $this->postJson('/accion')->assertCreated();

    expect($this->ejecuciones)->toBe(2);
});

it('sin usuario autenticado no guarda nada: ejecuta cada vez', function () {
    auth()->forgetGuards();
    $this->app['auth']->shouldUse('web');
    // Sin sesión: actingAs de beforeEach ya no aplica a una app nueva.
    $this->refreshApplication();
    $contador = 0;
    Route::post('/anonima', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador]);
    })->middleware(AccionIdempotente::class);

    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/anonima');
    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/anonima');

    expect($contador)->toBe(2);
});

it('una respuesta de error no se guarda: el reintento vuelve a ejecutar', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/falla')->assertStatus(422);
    $segunda = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/falla')->assertStatus(422);

    expect($segunda->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('la misma clave en otra ruta no reusa la respuesta', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    $respuesta = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/otra');

    expect($respuesta->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and($respuesta->json())->toBe(['otra' => true]);
});

it('con la clave en vuelo (lock tomado) el reintento no ejecuta: 503 con Retry-After', function () {
    config(['idempotencia.espera_del_lock' => 0]);
    $lock = Cache::lock(AccionIdempotente::claveDeLock(7, 'POST accion', $this->clave), 30);
    expect($lock->get())->toBeTrue();

    $respuesta = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion');

    $lock->release();

    // 503 y no 409: una cola típica descarta los 4xx (salvo 429) y reintenta los 5xx.
    $respuesta->assertStatus(503);
    expect($respuesta->headers->get('Retry-After'))->not->toBeNull()
        ->and($this->ejecuciones)->toBe(0);
});

it('el lock se libera aunque la acción falle', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/falla')->assertStatus(422);

    $lock = Cache::lock(AccionIdempotente::claveDeLock(7, 'POST falla', $this->clave), 5);

    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('el TTL y el prefijo salen de la configuración', function () {
    config(['idempotencia.prefijo' => 'miSistema', 'idempotencia.ttl' => 60]);

    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    expect(AccionIdempotente::claveDeCache(7, 'POST accion', $this->clave))->toStartWith('miSistema:respuesta:')
        ->and(Cache::has(AccionIdempotente::claveDeCache(7, 'POST accion', $this->clave)))->toBeTrue();

    $this->travel(61)->seconds();

    expect(Cache::has(AccionIdempotente::claveDeCache(7, 'POST accion', $this->clave)))->toBeFalse();
});
