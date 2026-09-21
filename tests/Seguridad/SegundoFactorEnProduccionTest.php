<?php

use Muni\Shared\Seguridad\SegundoFactorEnProduccion;
use Muni\Shared\Testing\AssertSegundoFactorNoSeRegala;
use PHPUnit\Framework\AssertionFailedError;

/**
 * `config/mfa.php` estaba en 7 sistemas y —a diferencia de todo lo demás de la
 * §1.5— NO es el mismo archivo: hay cinco variantes distintas. `atencionvecino`
 * describe un TOTP con `emisor` y sin `show_code`; `control-acceso` agrega el
 * tope de códigos enviados; los demás difieren en la redacción de los
 * comentarios. Encima, la configuración del segundo factor ya tiene dueño:
 * `laravel-muni-acceso` la expone como `acceso.mfa.*` leyendo las MISMAS
 * variables de entorno. Un segundo `mfa.*` en este paquete sería un segundo
 * interruptor para la misma cerradura.
 *
 * Lo que sí comparten las siete variantes es una ADVERTENCIA escrita en un
 * comentario —«en PRODUCCIÓN debe quedar en false»— que hoy no hace cumplir
 * nadie. Eso es lo que se extrae: la regla, no el archivo.
 */
uses(AssertSegundoFactorNoSeRegala::class)->in(__DIR__.'/SegundoFactorEnProduccionTest.php');

beforeEach(function () {
    $this->app['env'] = 'production';
});

/**
 * El entorno se restaura después de CADA caso.
 *
 * Estos candados fuerzan `production` para probarse, y hasta ahora lo dejaban
 * puesto. Con SQLite en memoria daba igual —la base ya estaba migrada—, pero
 * contra MariaDB la migración del caso siguiente entra en la confirmación de
 * `ConfirmableTrait` («¿de verdad querés correr esto en producción?») y la
 * suite muere con un BadMethodCallException sobre el OutputStyle simulado, que
 * no dice nada del entorno. Ocho casos en rojo solo al correr `test:mariadb`.
 */
afterEach(function () {
    app()['env'] = 'testing';
});

it('no objeta nada con el segundo factor encendido y el código oculto', function (string $prefijo) {
    config()->set($prefijo, ['enabled' => true, 'activa' => true, 'show_code' => false, 'mostrar_codigo' => false]);

    expect(SegundoFactorEnProduccion::problemas())->toBe([]);
})->with(['mfa', 'acceso.mfa']);

it('canta cuando el código se pinta en pantalla en producción', function (string $clave) {
    config()->set($clave, true);

    expect(SegundoFactorEnProduccion::problemas())->toHaveCount(1)
        ->and(SegundoFactorEnProduccion::problemas()[0])->toContain($clave);
})->with(['mfa.show_code', 'acceso.mfa.mostrar_codigo']);

it('canta cuando el interruptor del segundo factor quedó apagado en producción', function (string $clave) {
    config()->set($clave, false);

    expect(SegundoFactorEnProduccion::problemas())->toHaveCount(1)
        ->and(SegundoFactorEnProduccion::problemas()[0])->toContain($clave);
})->with(['mfa.enabled', 'acceso.mfa.activa']);

it('no exige segundo factor a un sistema que no declara ninguno', function () {
    config()->set('mfa', null);
    config()->set('acceso', null);

    expect(SegundoFactorEnProduccion::problemas())->toBe([]);
});

it('fuera de producción no molesta: apagar la MFA en desarrollo es lo normal', function () {
    $this->app['env'] = 'local';
    config()->set('mfa', ['enabled' => false, 'show_code' => true]);

    expect(SegundoFactorEnProduccion::problemas())->toBe([]);
});

it('junta los dos hallazgos cuando los dos están mal', function () {
    config()->set('mfa', ['enabled' => false, 'show_code' => true]);

    expect(SegundoFactorEnProduccion::problemas())->toHaveCount(2);
});

it('expone el aserto que cada sistema mete en su suite', function () {
    config()->set('mfa', ['enabled' => true, 'show_code' => false]);

    static::assertSegundoFactorNoSeRegala();
});

it('el aserto falla cuando el código se regala', function () {
    config()->set('mfa', ['enabled' => true, 'show_code' => true]);

    static::assertSegundoFactorNoSeRegala();
})->throws(AssertionFailedError::class);
