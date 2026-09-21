<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Muni\Shared\Casts\EncryptedSeguro;

/**
 * El cast que cifra en reposo sin romper las filas heredadas.
 *
 * Estaba copiado en 7 de los 8 sistemas (todos menos atencionvecino), byte a
 * byte salvo una palabra de comentario. Lo que se prueba acá es lo que hace
 * que valga la pena: que lo que queda EN LA BASE no sea el texto del vecino
 * —si eso no se cumple, el cast no sirve para nada— y que una fila vieja en
 * claro se siga leyendo en vez de tumbar la vista.
 */
beforeEach(function () {
    Schema::create('fichas_de_prueba', function ($tabla) {
        $tabla->id();
        $tabla->text('dato')->nullable();
    });
});

/** Modelo mínimo, solo para ejercitar el cast contra una tabla real. */
function fichaDePrueba(): Model
{
    return new class extends Model
    {
        protected $table = 'fichas_de_prueba';

        public $timestamps = false;

        protected $guarded = [];

        protected $casts = ['dato' => EncryptedSeguro::class];
    };
}

it('devuelve el mismo texto que se guardó', function () {
    $ficha = fichaDePrueba()->newInstance(['dato' => '12.345.678-5']);
    $ficha->save();

    expect($ficha->fresh()->dato)->toBe('12.345.678-5');
});

it('deja el dato cifrado en la base, no en texto plano', function () {
    $ficha = fichaDePrueba()->newInstance(['dato' => 'Domicilio del vecino 123']);
    $ficha->save();

    $enLaBase = (string) DB::table('fichas_de_prueba')->where('id', $ficha->id)->value('dato');

    expect($enLaBase)
        ->not->toBe('Domicilio del vecino 123')
        ->not->toContain('Domicilio')
        ->not->toContain('vecino');
});

it('lee en claro una fila heredada que nunca pasó por el cast', function () {
    DB::table('fichas_de_prueba')->insert(['id' => 7, 'dato' => 'texto plano heredado']);

    expect(fichaDePrueba()->newQuery()->find(7)->dato)->toBe('texto plano heredado');
});

it('reescribe cifrada la fila heredada que se vuelve a guardar', function () {
    DB::table('fichas_de_prueba')->insert(['id' => 8, 'dato' => 'texto plano heredado']);

    $ficha = fichaDePrueba()->newQuery()->find(8);
    $ficha->dato = 'texto corregido';
    $ficha->save();

    expect((string) DB::table('fichas_de_prueba')->where('id', 8)->value('dato'))
        ->not->toContain('texto corregido');
});

it('deja pasar null y la cadena vacía sin cifrarlos', function (?string $vacio) {
    $ficha = fichaDePrueba()->newInstance(['dato' => $vacio]);
    $ficha->save();

    expect(DB::table('fichas_de_prueba')->where('id', $ficha->id)->value('dato'))->toBe($vacio)
        ->and($ficha->fresh()->dato)->toBe($vacio);
})->with([
    'null' => [null],
    'cadena vacía' => [''],
]);
