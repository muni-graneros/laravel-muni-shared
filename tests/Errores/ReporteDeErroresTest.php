<?php

use Illuminate\Support\Facades\Log;
use Muni\Shared\Errores\ReporteDeErrores;

/**
 * El candado de «los errores no salen del país», portado byte a byte desde
 * `App\Support\ReporteDeErrores` (idéntico en 7 de los 8 sistemas).
 *
 * Una traza de excepción lleva la ruta, la consulta y a veces el cuerpo del
 * request: en un sistema municipal, datos de un vecino. Mandarla a sentry.io es
 * una transferencia internacional de datos personales que la Ley 21.719 no
 * permite sin base de licitud. Lo que se prueba acá es que el destino PROPIO se
 * respeta —si se rechazara, nadie vería los errores y alguien apagaría el
 * chequeo— y que el ajeno se rechaza incluso cuando viene disfrazado.
 *
 * Los casos son los mismos que ejercita `Muni\Candados\Candados\ErroresNoSalenDelPais`
 * en cada sistema: si esta clase y ese candado se desalinean, hay que enterarse acá.
 */
it('acepta el GlitchTip municipal: el destino propio se respeta', function (string $dsn) {
    config()->set('sentry.dsn', $dsn);

    expect(ReporteDeErrores::vaADestinoPropio())->toBeTrue();
})->with([
    'dominio municipal' => ['https://clave@errores.graneros.cl/1'],
    'IP y puerto en la isla' => ['https://clave@127.0.0.1:8400/1'],
    'servicio de la red de Docker' => ['https://clave@glitchtip-web:8000/1'],
    'un host que CONTIENE el dominio ajeno pero es nuestro' => ['https://clave@sentry.io.graneros.cl/1'],
]);

it('rechaza la nube de Sentry, que está fuera de Chile', function (string $dsn) {
    config()->set('sentry.dsn', $dsn);

    expect(ReporteDeErrores::vaADestinoPropio())->toBeFalse();
})->with([
    'dominio pelado' => ['https://clave@sentry.io/4501'],
    'ingest regional' => ['https://clave@o123456.ingest.sentry.io/4501'],
    'ingest de Estados Unidos' => ['https://clave@o123456.ingest.us.sentry.io/4501'],
    'subdominio cualquiera' => ['https://clave@errores.sentry.io/4501'],
    'con el host en mayúsculas' => ['https://clave@Errores.SENTRY.IO/4501'],
]);

it('sin DSN no se engancha nada', function (mixed $dsn) {
    config()->set('sentry.dsn', $dsn);

    expect(ReporteDeErrores::vaADestinoPropio())->toBeFalse();
})->with([
    'null' => [null],
    'cadena vacía' => [''],
    'solo espacios' => ['   '],
]);

it('ante un DSN ilegible no manda nada y deja constancia', function (string $dsn) {
    Log::spy();
    config()->set('sentry.dsn', $dsn);

    expect(ReporteDeErrores::vaADestinoPropio())->toBeFalse();

    Log::shouldHaveReceived('warning')->once();
})->with([
    'no es una URL' => ['esto-no-es-una-url'],
    'esquema sin host' => ['https://'],
]);

it('avisa por el log cuando el DSN apunta a un servicio de terceros', function () {
    Log::spy();
    config()->set('sentry.dsn', 'https://clave@o1.ingest.sentry.io/4501');

    expect(ReporteDeErrores::vaADestinoPropio())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $mensaje, array $contexto) => str_contains($mensaje, '21.719')
            && $contexto['host'] === 'o1.ingest.sentry.io');
});
