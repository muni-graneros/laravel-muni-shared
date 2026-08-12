<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Muni\Shared\Correo\ConfiguracionDeCorreo;
use Muni\Shared\Correo\TransporteGraph;

beforeEach(function () {
    config([
        'mail.default' => 'graph',
        'mail.mailers.graph.transport' => 'graph',
        'mail.mailers.graph.tenant' => 'un-tenant',
        'mail.mailers.graph.cliente' => 'un-cliente',
        'mail.mailers.graph.secreto' => 'un-secreto-muy-privado',
        'mail.mailers.graph.remitente' => 'no-reply@municipalidadgraneros.cl',
        'mail.from.address' => 'no-reply@municipalidadgraneros.cl',
    ]);

    Cache::forget('correo:graph:token');
});

function microsoftResponde(): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'un-permiso']),
        'graph.microsoft.com/*' => Http::response('', 202),
    ]);
}

it('el paquete registra el transporte sin que el sistema toque su config/mail.php', function () {
    // Es lo que hace que agregar el correo a un sistema sea poner variables en
    // el .env y nada más. Si el bloque dejara de fusionarse, cada sistema
    // tendría que editar su configuración y nadie se enteraría hasta intentar
    // enviar.
    microsoftResponde();

    expect(Mail::mailer('graph')->getSymfonyTransport())
        ->toBeInstanceOf(TransporteGraph::class);
});

it('manda por la API y no por SMTP', function () {
    microsoftResponde();

    Mail::raw('hola', fn ($m) => $m->to('alguien@ejemplo.cl')->subject('prueba'));

    Http::assertSent(fn ($p): bool => str_contains($p->url(), 'graph.microsoft.com')
        && str_contains($p->url(), 'sendMail'));
});

it('llega a destinatarios de fuera del municipio', function () {
    // Es la diferencia con la entrega directa, que solo entrega dentro del
    // dominio. Sin esto no se le podría escribir a nadie de fuera, y no habría
    // ningún error que lo delatara.
    microsoftResponde();

    Mail::raw('hola', fn ($m) => $m->to('vecino@gmail.com')->subject('prueba'));

    Http::assertSent(function ($peticion): bool {
        if (! str_contains($peticion->url(), 'sendMail')) {
            return false;
        }

        return str_contains(base64_decode($peticion->body(), true) ?: '', 'vecino@gmail.com');
    });
});

it('manda el mensaje completo, no solo el texto', function () {
    microsoftResponde();

    Mail::raw('el cuerpo', fn ($m) => $m->to('alguien@ejemplo.cl')->subject('un asunto'));

    $mime = '';

    Http::assertSent(function ($peticion) use (&$mime): bool {
        if (str_contains($peticion->url(), 'sendMail')) {
            $mime = base64_decode($peticion->body(), true) ?: '';
        }

        return true;
    });

    expect($mime)->toContain('un asunto')
        ->and($mime)->toContain('no-reply@municipalidadgraneros.cl');
});

it('reaprovecha el permiso en vez de pedir uno por cada correo', function () {
    microsoftResponde();

    foreach (['uno@ejemplo.cl', 'dos@ejemplo.cl', 'tres@ejemplo.cl'] as $destino) {
        Mail::raw('hola', fn ($m) => $m->to($destino)->subject('prueba'));
    }

    $permisos = 0;

    Http::assertSent(function ($peticion) use (&$permisos): bool {
        if (str_contains($peticion->url(), 'login.microsoftonline.com')) {
            $permisos++;
        }

        return true;
    });

    expect($permisos)->toBe(1);
});

it('manda igual aunque la caché no responda', function () {
    // Guardar el permiso es una optimización, no un requisito. Que Redis se
    // caiga no tiene por qué cortar los códigos de acceso al panel.
    microsoftResponde();

    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis caído'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('redis caído'));

    Mail::raw('hola', fn ($m) => $m->to('alguien@ejemplo.cl')->subject('prueba'));

    Http::assertSent(fn ($p): bool => str_contains($p->url(), 'sendMail'));
});

it('no repite el secreto en el error cuando Microsoft no da el permiso', function () {
    // Los errores de autenticación suelen devolver parte de lo enviado, y ese
    // mensaje termina en el log y en la tabla de trabajos fallidos.
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(
            ['error_description' => 'AADSTS7000215: Invalid client secret un-secreto-muy-privado'],
            401,
        ),
    ]);

    $transporte = new TransporteGraph('t', 'c', 'un-secreto-muy-privado', 'no-reply@municipalidadgraneros.cl');

    try {
        $transporte->permiso();
        $mensaje = '';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
    }

    expect($mensaje)->not->toBeEmpty()
        ->and($mensaje)->not->toContain('un-secreto-muy-privado');
});

it('conserva el motivo real cuando Microsoft rechaza el envío', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'un-permiso']),
        'graph.microsoft.com/*' => Http::response(
            ['error' => ['message' => 'Access to OData is disabled.']],
            403,
        ),
    ]);

    try {
        Mail::raw('hola', fn ($m) => $m->to('alguien@ejemplo.cl')->subject('prueba'));
        $mensaje = '';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
    }

    // Sin el motivo solo queda un 403, que no dice si falta el permiso o la
    // política de acceso: son dos pedidos distintos a informática.
    expect($mensaje)->toContain('Access to OData is disabled.');
});

describe('diagnóstico', function () {
    it('reconoce el modo por Graph', function () {
        expect(ConfiguracionDeCorreo::modoActual())->toBe('graph');
    });

    it('enumera las credenciales que faltan', function () {
        config(['mail.mailers.graph.secreto' => '', 'mail.mailers.graph.cliente' => null]);

        expect(ConfiguracionDeCorreo::credencialesQueFaltan())
            ->toContain('MICROSOFT_GRAPH_CLIENT_SECRET')
            ->toContain('MICROSOFT_GRAPH_CLIENT_ID')
            ->not->toContain('MICROSOFT_GRAPH_TENANT_ID');
    });

    it('avisa si el remitente no es la casilla autorizada', function () {
        expect(ConfiguracionDeCorreo::problemas('graph', 'otra@municipalidadgraneros.cl'))
            ->not->toBeEmpty();
    });

    it('cuenta los días que faltan para el vencimiento', function () {
        config(['mail.mailers.graph.vence' => now()->addDays(45)->format('Y-m-d')]);

        expect(ConfiguracionDeCorreo::diasHastaElVencimiento())->toBe(45);
    });

    it('da negativo si ya venció', function () {
        config(['mail.mailers.graph.vence' => now()->subDays(3)->format('Y-m-d')]);

        expect(ConfiguracionDeCorreo::diasHastaElVencimiento())->toBeLessThan(0);
    });

    it('devuelve null si la fecha falta o no se entiende', function () {
        config(['mail.mailers.graph.vence' => null]);
        expect(ConfiguracionDeCorreo::diasHastaElVencimiento())->toBeNull();

        config(['mail.mailers.graph.vence' => 'el año que viene']);
        expect(ConfiguracionDeCorreo::diasHastaElVencimiento())->toBeNull();
    });

    it('distingue un fallo de red local de un rechazo de Microsoft', function () {
        // Correr el comando fuera del contenedor da un error de resolución de
        // nombres. Reportarlo como «Microsoft no autorizó» manda a reclamarle a
        // informática por algo que está de este lado.
        expect(ConfiguracionDeCorreo::esFalloDeRedLocal(
            'php_network_getaddresses: getaddrinfo for redis failed'
        ))->toBeTrue();

        expect(ConfiguracionDeCorreo::esFalloDeRedLocal(
            'Microsoft no entregó el permiso para enviar correo (HTTP 401)'
        ))->toBeFalse();
    });
});

describe('el comando correo:probar', function () {
    it('dice qué credenciales faltan', function () {
        config(['mail.mailers.graph.secreto' => '']);

        $this->artisan('correo:probar')
            ->expectsOutputToContain('MICROSOFT_GRAPH_CLIENT_SECRET')
            ->assertFailed();
    });

    it('pasa cuando Microsoft concede el permiso', function () {
        microsoftResponde();

        $this->artisan('correo:probar')
            ->expectsOutputToContain('Concedido')
            ->assertSuccessful();
    });

    it('no le echa la culpa a Microsoft por un fallo de red', function () {
        Http::fake(function () {
            throw new RuntimeException('php_network_getaddresses: getaddrinfo for redis failed');
        });

        $this->artisan('correo:probar')
            ->expectsOutputToContain('No se pudo llegar a Microsoft desde este servidor')
            ->doesntExpectOutputToContain('Microsoft no autorizó')
            ->assertFailed();
    });

    it('avisa cuando el permiso está por vencer', function () {
        microsoftResponde();
        config(['mail.mailers.graph.vence' => now()->addDays(12)->format('Y-m-d')]);

        $this->artisan('correo:probar')->expectsOutputToContain('Vence en 12 días');
    });
});

describe('modos que no sirven', function () {
    it('avisa que la entrega directa no puede entregar', function () {
        // Sin este aviso, un sistema con MAIL_MAILER=smtp y sin credenciales
        // pasaría la revisión de salud en verde sin poder mandar nada.
        expect(ConfiguracionDeCorreo::problemas('entrega-directa', 'no-reply@municipalidadgraneros.cl'))
            ->not->toBeEmpty();
    });

    it('no reclama nada si el correo se está escribiendo en un archivo', function () {
        expect(ConfiguracionDeCorreo::problemas('sin-envio', 'lo-que-sea@ejemplo.cl'))->toBeEmpty();
    });
});
