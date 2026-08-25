<?php

use Illuminate\Database\Eloquent\Model;
use Muni\Shared\Privacidad\Ciclo\EtiquetaDeTitular;
use Muni\Shared\Privacidad\Contratos\BuscaTitulares;
use Muni\Shared\Privacidad\Contratos\TitularDeDatos;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    $this->persona = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    $this->buscador = new class implements BuscaTitulares
    {
        public function buscar(string $termino): array
        {
            return PersonaDePrueba::query()
                ->where('nombre', 'like', '%'.$termino.'%')
                ->get()
                ->mapWithKeys(fn (PersonaDePrueba $p): array => [
                    $p->getKey() => (string) EtiquetaDeTitular::de($p),
                ])
                ->all();
        }

        public function encontrar(int|string $clave): (Model&TitularDeDatos)|null
        {
            return PersonaDePrueba::find($clave);
        }
    };
});

it('un buscador del adoptante cumple el contrato del módulo', function () {
    expect($this->buscador->buscar('Rocío'))->toContain('Rocío Paredes — 11.111.111-1')
        ->and($this->buscador->buscar('Nadie'))->toBe([]);
});

it('resuelve la clave elegida en un titular de verdad, sin volver a buscar por texto', function () {
    $titular = $this->buscador->encontrar($this->persona->getKey());

    expect($titular)->toBeInstanceOf(TitularDeDatos::class)
        ->and($titular->titularDocumento())->toBe('11.111.111-1')
        ->and($this->buscador->encontrar(999))->toBeNull();
});
