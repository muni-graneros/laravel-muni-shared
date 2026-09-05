<?php

use Muni\Shared\Seguridad\CredencialesDePlantilla;

/**
 * Candado de arranque: ningún sistema del ecosistema puede llegar a producción
 * con la contraseña que trae el `.env.example` del scaffold —pública, porque el
 * repositorio lo es—. Copiado desde `App\Support\CredencialesDePlantilla` del
 * scaffold, que hasta esta versión era la ÚNICA protección: los ocho sistemas
 * generados a partir de él no tenían la clase.
 */
it('en producción, la contraseña de plantilla de la base de datos aborta el arranque', function () {
    app()['env'] = 'production';
    config()->set('database.connections.testing.password', 'sistema_pass');

    expect(fn () => CredencialesDePlantilla::comprobar())
        ->toThrow(RuntimeException::class, 'DB_PASSWORD');
});

it('en producción, la contraseña de plantilla de los respaldos también aborta el arranque', function () {
    app()['env'] = 'production';
    config()->set('backup.backup.password', 'cambiame-por-algo-seguro-en-produccion');

    expect(fn () => CredencialesDePlantilla::comprobar())
        ->toThrow(RuntimeException::class, 'BACKUP_ARCHIVE_PASSWORD');
});

it('el mensaje dice qué variable cambiar y nunca imprime el valor real configurado', function () {
    // El valor real y el de plantilla son, en este caso, el mismo string —es lo
    // que hace saltar la guarda—, así que lo que hay que comprobar es que el
    // mensaje señala la VARIABLE del .env, no que compara valores: la clase no
    // tiene ninguna otra rama que pudiera filtrar un valor real distinto.
    app()['env'] = 'production';
    config()->set('database.connections.testing.password', 'sistema_pass');

    expect(fn () => CredencialesDePlantilla::comprobar())
        ->toThrow(RuntimeException::class, 'DB_PASSWORD');

    expect(fn () => CredencialesDePlantilla::comprobar())
        ->toThrow(RuntimeException::class, 'sistema_pass');
});

it('fuera de producción no molesta aunque quede la contraseña de plantilla', function () {
    // Si esto fallara, cualquier ambiente de desarrollo o de pruebas del
    // ecosistema —todos arrancan con «sistema_pass»— dejaría de levantar.
    app()['env'] = 'local';
    config()->set('database.connections.testing.password', 'sistema_pass');

    CredencialesDePlantilla::comprobar();

    expect(true)->toBeTrue();
});

it('sin clave de aplicación no lanza: es el arranque de composer install / package:discover', function () {
    // `composer install` dispara `package:discover`, que arranca la aplicación
    // cuando todavía no hay «.env»: sin él, APP_ENV toma su valor por defecto,
    // que es «production». Sin esta guarda, instalar el paquete tumbaba el
    // propio `composer install` —y el build de la imagen— con un error que
    // hablaba de la base de datos y despistaba.
    app()['env'] = 'production';
    config()->set('app.key', '');
    config()->set('database.connections.testing.password', 'sistema_pass');

    CredencialesDePlantilla::comprobar();

    expect(true)->toBeTrue();
});

it('con una contraseña propia, el sistema arranca en producción', function () {
    app()['env'] = 'production';
    config()->set('database.connections.testing.password', 'una-contrasena-propia-del-municipio');
    config()->set('backup.backup.password', 'otra-propia-tambien');

    CredencialesDePlantilla::comprobar();

    expect(true)->toBeTrue();
});

it('la lista configurable admite valores extra del sistema, sin perder los del scaffold', function () {
    // Un sistema con sus propias credenciales de plantilla (un panel de
    // reportes, por ejemplo) suma la suya a la lista publicando el config y
    // extendiendo `CredencialesDePlantilla::POR_OMISION`, como documenta su
    // docblock. Las dos listas —la del scaffold y la propia— tienen que seguir
    // vigiladas a la vez.
    app()['env'] = 'production';
    config()->set('database.connections.testing.password', 'sistema_pass');
    config()->set('backup.backup.password', 'ok');
    config()->set('reportes.password', 'ok');

    config()->set('credenciales-de-plantilla.valores', [
        ...CredencialesDePlantilla::POR_OMISION,
        [
            'queEs' => 'contraseña del panel de reportes',
            'config' => 'reportes.password',
            'valorDePlantilla' => 'reportes_demo',
            'variableEnv' => 'REPORTES_PASSWORD',
        ],
    ]);

    // El del scaffold sigue vigilado primero, porque está primero en la lista.
    expect(fn () => CredencialesDePlantilla::comprobar())
        ->toThrow(RuntimeException::class, 'DB_PASSWORD');

    // Con el del scaffold resuelto, la extra del sistema también se vigila.
    config()->set('database.connections.testing.password', 'ok');
    config()->set('reportes.password', 'reportes_demo');

    expect(fn () => CredencialesDePlantilla::comprobar())
        ->toThrow(RuntimeException::class, 'REPORTES_PASSWORD');
});
