<?php

use Filament\Resources\Pages\ListRecords;
use Muni\Shared\Auditoria\Pages\ListActivitiesBase;

/**
 * Lo único que valía la pena portar del `ListActivities` de cada sistema
 * (idéntico en 5 de los 8: licencias, seguridad, control-acceso, web, rrhh)
 * es el sitio único al que hoy no puede llegar ningún comportamiento común
 * sin editar N copias. Cada sistema sigue declarando su propio `$resource`
 * -apunta a SU `ActivityResource`, que no es portable-, así que el candado
 * es de herencia y de forma, no de comportamiento: no hay nada que "hacer"
 * todavía en la clase base, y por eso el test no compara resultados sino
 * que la cadena de herencia sea la correcta.
 *
 * `discapacidad-graneros` diverge SOLO en namespace
 * (`App\Filament\Discapacidad\Resources\...`, por su panel Filament
 * multi-panel) y no en contenido: el archivo es idéntico salvo esa ruta.
 */
it('es una página de listado de Filament, para que cada sistema declare su propio $resource', function () {
    expect(is_subclass_of(ListActivitiesBase::class, ListRecords::class))->toBeTrue();
});

it('no fija $resource: cada sistema apunta a SU ActivityResource local', function () {
    $reflexion = new ReflectionClass(ListActivitiesBase::class);

    expect($reflexion->isAbstract())->toBeTrue();
});

it('un sistema que la extiende y declara $resource lo expone vía getResource()', function () {
    $paginaDelSistema = new class extends ListActivitiesBase
    {
        protected static string $resource = 'App\Filament\Resources\ActivityResource';
    };

    expect($paginaDelSistema::getResource())->toBe('App\Filament\Resources\ActivityResource');
});
