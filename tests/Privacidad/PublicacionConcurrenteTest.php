<?php

use Illuminate\Support\Facades\DB;
use Muni\Shared\Privacidad\Modelos\TextoInformativo;
use Muni\Shared\Privacidad\PublicacionEnConflicto;
use Muni\Shared\Privacidad\Textos;

/**
 * La carrera de `Textos::publicar()`: entre `max(version)` y el INSERT cabe
 * otra publicación del mismo (sistema, codigo).
 *
 * La concurrencia se simula metiendo la fila del rival desde el evento
 * `creating`, que es el instante exacto en que se abre la ventana. No es un
 * segundo proceso de verdad —con SQLite en memoria hay una sola conexión— pero
 * reproduce lo único que importa acá: que el INSERT se encuentre el número de
 * versión ya tomado.
 */
beforeEach(fn () => config(['privacidad.sistema' => 'discapacidad']));

/** Hace que las próximas `$veces` publicaciones se encuentren el número tomado. */
function pisarLaVersion(int $veces): void
{
    TextoInformativo::creating(function (TextoInformativo $texto) use (&$veces): void {
        if ($veces-- <= 0) {
            return;
        }

        DB::table('privacidad_textos')->insert([
            'sistema' => $texto->sistema,
            'codigo' => $texto->codigo,
            'version' => $texto->version,
            'contenido' => 'publicado por el otro',
            'hash' => hash('sha256', 'publicado por el otro'),
            'vigente_desde' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

it('reintenta cuando otra publicación se llevó el número de versión', function () {
    pisarLaVersion(1);

    $texto = app(Textos::class)->publicar('aviso_recoleccion', 'El mío');

    expect($texto->contenido)->toBe('El mío')
        ->and($texto->exists)->toBeTrue()
        ->and(app(Textos::class)->vigente('aviso_recoleccion')->contenido)->toBe('El mío');
});

it('el intento fallido no deja rastro: ni versión a medias ni vigencia cerrada de más', function () {
    $primera = app(Textos::class)->publicar('aviso_recoleccion', 'Primera');
    pisarLaVersion(1);

    app(Textos::class)->publicar('aviso_recoleccion', 'Segunda');

    // La fila del rival se fue con el rollback del intento fallido, así que
    // quedan exactamente dos: la primera cerrada y la segunda vigente.
    expect(TextoInformativo::count())->toBe(2)
        ->and($primera->fresh()->vigente_hasta)->not->toBeNull()
        ->and($primera->fresh()->contenido)->toBe('Primera');
});

it('un choque permanente falla con excepción de dominio, no con una del driver', function () {
    pisarLaVersion(99);

    expect(fn () => app(Textos::class)->publicar('aviso_recoleccion', 'El mío'))
        ->toThrow(PublicacionEnConflicto::class, 'otra publicación se llevó el número de versión');
});

it('el texto vigente sobrevive intacto a una publicación que no se pudo resolver', function () {
    $vigente = app(Textos::class)->publicar('aviso_recoleccion', 'La buena');
    pisarLaVersion(99);

    rescue(fn () => app(Textos::class)->publicar('aviso_recoleccion', 'La que no entró'));

    expect(app(Textos::class)->vigente('aviso_recoleccion')?->is($vigente))->toBeTrue()
        ->and($vigente->fresh()->vigente_hasta)->toBeNull()
        ->and(TextoInformativo::count())->toBe(1);
});
