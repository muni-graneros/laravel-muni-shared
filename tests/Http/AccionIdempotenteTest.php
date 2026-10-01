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

    Route::match(['POST', 'PUT'], '/ambos', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador]);
    })->middleware(AccionIdempotente::class);

    Route::post('/sin-contenido', function () use (&$contador) {
        $contador++;

        return response()->noContent();
    })->middleware(AccionIdempotente::class);

    Route::post('/texto', function () use (&$contador) {
        $contador++;

        return response("hecho {$contador}", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    })->middleware(AccionIdempotente::class);

    Route::post('/flujo', function () use (&$contador) {
        $contador++;

        return response()->stream(function () {
            echo 'x';
        });
    })->middleware(AccionIdempotente::class);

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
    $lock = Cache::lock(AccionIdempotente::claveDeLock($this->usuario, 'POST accion', $this->clave), 30);
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

    $lock = Cache::lock(AccionIdempotente::claveDeLock($this->usuario, 'POST falla', $this->clave), 5);

    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('el TTL y el prefijo salen de la configuración', function () {
    config(['idempotencia.prefijo' => 'miSistema', 'idempotencia.ttl' => 60]);

    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    expect(AccionIdempotente::claveDeCache($this->usuario, 'POST accion', $this->clave))->toStartWith('miSistema:respuesta:')
        ->and(Cache::has(AccionIdempotente::claveDeCache($this->usuario, 'POST accion', $this->clave)))->toBeTrue();

    $this->travel(61)->seconds();

    expect(Cache::has(AccionIdempotente::claveDeCache($this->usuario, 'POST accion', $this->clave)))->toBeFalse();
});

it('dos usuarios con id NO numérico (UUID) no comparten la respuesta', function () {
    // Un (int) del identificador convertía cualquier UUID que empiece con letra en 0:
    // todos esos usuarios compartían la misma clave y uno leía la respuesta del otro.
    $ana = new User;
    $ana->setKeyType('string');
    $ana->id = 'a1b2c3d4-0000-4000-8000-000000000001';
    $this->actingAs($ana);
    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    $beto = new User;
    $beto->setKeyType('string');
    $beto->id = 'b1b2c3d4-0000-4000-8000-000000000002';
    $this->actingAs($beto);
    $respuesta = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    expect($this->ejecuciones)->toBe(2)
        ->and($respuesta->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and($respuesta->json('n'))->toBe(2);
});

it('el mismo id en otro modelo de usuario (otro guard) no comparte la respuesta', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    $vecino = new class extends User {};
    $vecino->id = 7;
    $this->actingAs($vecino);

    $respuesta = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/accion')->assertCreated();

    expect($this->ejecuciones)->toBe(2)
        ->and($respuesta->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('la misma clave y ruta con otro método no reusa la respuesta', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/ambos')->assertOk();
    $respuesta = $this->withHeader('Idempotency-Key', $this->clave)->putJson('/ambos')->assertOk();

    expect($this->ejecuciones)->toBe(2)
        ->and($respuesta->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('un 2xx sin cuerpo (204) también es idempotente: no se ejecuta dos veces', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->post('/sin-contenido')->assertNoContent();
    $segunda = $this->withHeader('Idempotency-Key', $this->clave)->post('/sin-contenido')->assertNoContent();

    expect($this->ejecuciones)->toBe(1)
        ->and($segunda->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('un 2xx que no es JSON se repite con su cuerpo y su Content-Type', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->post('/texto')->assertOk();
    $segunda = $this->withHeader('Idempotency-Key', $this->clave)->post('/texto')->assertOk();

    expect($this->ejecuciones)->toBe(1)
        ->and($segunda->getContent())->toBe('hecho 1')
        ->and($segunda->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
        ->and($segunda->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('la repetición no reenvía cabeceras de la respuesta original (cookies)', function () {
    Route::post('/con-cookie', fn () => response()->json(['ok' => true])->withCookie(cookie('sesion_ajena', 'secreto')))
        ->middleware(AccionIdempotente::class);

    $this->withHeader('Idempotency-Key', $this->clave)->postJson('/con-cookie')->assertOk();
    $segunda = $this->withHeader('Idempotency-Key', $this->clave)->postJson('/con-cookie')->assertOk();

    expect($segunda->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($segunda->headers->getCookies())->toBe([]);
});

it('una respuesta en streaming no se puede guardar: se ejecuta cada vez y no rompe', function () {
    $this->withHeader('Idempotency-Key', $this->clave)->post('/flujo')->assertOk();
    $this->withHeader('Idempotency-Key', $this->clave)->post('/flujo')->assertOk();

    expect($this->ejecuciones)->toBe(2);
});
