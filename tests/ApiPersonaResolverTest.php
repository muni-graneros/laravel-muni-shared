<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Muni\Shared\Persona\ApiPersonaResolver;
use Muni\Shared\Persona\MaestroNoDisponible;

/**
 * Lo que este archivo vigila: que el RUT del vecino NUNCA viaje dentro de una
 * excepción que sale del resolver.
 *
 * Guzzle arma el mensaje de sus excepciones con la URI completa («cURL error
 * 28: … for https://maestro/api/servicios/v1/personas/11111111-1»), y
 * Laravel lo conserva tal cual al envolverlo en `ConnectionException`. Ese
 * mensaje termina en laravel.log y en GlitchTip por el handler del sistema
 * consumidor —que no puede saber que adentro va un dato personal—, y también
 * en el `Log::warning` con `$e->getMessage()` que hacen los respaldos locales.
 * La Ley 21.719 pide minimización: un log de errores no es lugar para un RUT.
 */
const RUT_DE_PRUEBA = '11111111-1';

/** Todos los mensajes de la cadena de excepciones, juntos. */
function cadenaDeMensajes(Throwable $e): string
{
    $mensajes = [];

    for ($actual = $e; $actual !== null; $actual = $actual->getPrevious()) {
        $mensajes[] = $actual::class.': '.$actual->getMessage();
    }

    return implode("\n", $mensajes);
}

beforeEach(function () {
    config([
        'services.personas_api.url' => 'http://personas-api:8000',
        'services.personas_api.token' => 'token-de-prueba',
        'services.personas_api.sistema' => 'discapacidad',
    ]);
});

it('devuelve el DTO cuando el maestro conoce el RUT', function () {
    Http::fake([
        '*/api/servicios/v1/personas/*' => Http::response([
            'data' => ['rut' => RUT_DE_PRUEBA, 'nombres' => 'Rocío', 'apellidos' => 'Paredes'],
        ]),
    ]);

    $persona = (new ApiPersonaResolver)->findByRut(RUT_DE_PRUEBA);

    expect($persona)->not->toBeNull()
        ->and($persona->nombres)->toBe('Rocío');
});

it('un 404 es persona nueva: null, sin lanzar', function () {
    Http::fake(['*/api/servicios/v1/personas/*' => Http::response(['found' => false], 404)]);

    expect((new ApiPersonaResolver)->findByRut(RUT_DE_PRUEBA))->toBeNull();
});

it('un fallo de red lanza una excepción del dominio que no lleva el RUT en ninguna parte', function () {
    // El mensaje es el que arma Guzzle de verdad: la URI completa, con el RUT
    // en el path. Es lo que hoy sube al handler del consumidor.
    Http::fake([
        '*/api/servicios/v1/personas/*' => fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received '
            .'for http://personas-api:8000/api/servicios/v1/personas/'.RUT_DE_PRUEBA,
        ),
    ]);

    try {
        (new ApiPersonaResolver)->findByRut(RUT_DE_PRUEBA);
        $this->fail('Tenía que lanzar.');
    } catch (MaestroNoDisponible $e) {
        // Se mira la cadena entera, no solo el mensaje de arriba: Monolog
        // imprime los `previous` en laravel.log y el SDK de Sentry/GlitchTip
        // serializa la cadena completa. Un RUT escondido en un `previous` es
        // un RUT en el log.
        expect(cadenaDeMensajes($e))->not->toContain('11111111')
            ->and($e->status)->toBeNull();
    }
});

it('un error del maestro lanza la excepción del dominio con el status y sin el cuerpo de la respuesta', function () {
    // El maestro puede devolver el RUT en el cuerpo del error («El RUT
    // 11111111-1 no es válido»), y `RequestException` de Laravel pega un
    // resumen del cuerpo en su mensaje.
    Http::fake([
        '*/api/servicios/v1/personas/*' => Http::response(
            ['message' => 'Error interno consultando el RUT '.RUT_DE_PRUEBA],
            500,
        ),
    ]);

    try {
        (new ApiPersonaResolver)->findByRut(RUT_DE_PRUEBA);
        $this->fail('Tenía que lanzar.');
    } catch (MaestroNoDisponible $e) {
        expect(cadenaDeMensajes($e))->not->toContain('11111111')
            ->and($e->status)->toBe(500)
            ->and($e->getMessage())->toContain('500');
    }
});

it('la excepción del dominio sigue siendo atrapable como RuntimeException por los respaldos locales', function () {
    // discapacidad y feria envuelven este resolver en un `PersonaResolverConRespaldo`
    // que atrapa `Throwable`; el contrato no cambia para ellos.
    Http::fake(['*/api/servicios/v1/personas/*' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => (new ApiPersonaResolver)->findByRut(RUT_DE_PRUEBA))
        ->toThrow(RuntimeException::class);
});

it('el fallo de red con el ConnectException de Guzzle encadenado se traduce igual, sin el RUT', function () {
    // Laravel encadena el `ConnectException` de Guzzle como `previous` de su
    // `ConnectionException`, y ese es el camino real de un timeout en
    // producción. Guzzle 8 eliminó `getHandlerContext()` —de donde salía el
    // número de cURL—, así que leerlo a ciegas lanza un `Error` DENTRO de
    // `MaestroNoDisponible::porExcepcion()`: el consumidor deja de recibir la
    // excepción del dominio y le sube el fallo original, que lleva la URI con
    // el RUT en el path. Es una fuga de dato personal al log, no solo un
    // problema de tipos.
    $uriConRut = 'http://personas-api:8000/api/servicios/v1/personas/'.RUT_DE_PRUEBA;

    Http::fake([
        '*/api/servicios/v1/personas/*' => fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for '.$uriConRut,
            0,
            new ConnectException(
                'cURL error 28: Operation timed out for '.$uriConRut,
                new Request('GET', $uriConRut),
            ),
        ),
    ]);

    try {
        (new ApiPersonaResolver)->findByRut(RUT_DE_PRUEBA);
        $this->fail('Tenía que lanzar.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(MaestroNoDisponible::class)
            ->and(cadenaDeMensajes($e))->not->toContain('11111111')
            ->and(cadenaDeMensajes($e))->not->toContain('personas-api');
    }
});
