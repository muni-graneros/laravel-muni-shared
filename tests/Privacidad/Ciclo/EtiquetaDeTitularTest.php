<?php

use Illuminate\Support\Str;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Ciclo\EtiquetaDeTitular;
use Muni\Shared\Privacidad\EstadoDeSolicitud;
use Muni\Shared\Privacidad\Modelos\Finalidad;
use Muni\Shared\Privacidad\Modelos\Solicitud;
use Muni\Shared\Privacidad\Solicitante;
use Muni\Shared\Privacidad\TipoDeSolicitud;
use Muni\Shared\Tests\Privacidad\Fixtures\PersonaDePrueba;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);

    Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'atencion', 'nombre' => 'Atenciones',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);

    $this->titular = PersonaDePrueba::create([
        'nombre' => 'Rocío Paredes',
        'documento' => '11.111.111-1',
        'fecha_nacimiento' => now()->subYears(40)->toDateString(),
    ]);

    $this->crear = function (?PersonaDePrueba $titular): Solicitud {
        return Solicitud::create([
            'sistema' => 'discapacidad',
            'tipo' => TipoDeSolicitud::Acceso,
            'estado' => EstadoDeSolicitud::Recibida,
            'titular_type' => $titular?->getMorphClass() ?? PersonaDePrueba::class,
            'titular_id' => $titular?->getKey(),
            'titular_ref' => Str::random(32),
            'detalle' => 'Pide copia de su ficha.',
            'solicitante' => Solicitante::Titular,
            'verificacion_identidad' => ['medio' => 'cedula_presencial'],
            'recibida_en' => now(),
            'vence_en' => now()->addDays(30),
        ]);
    };
});

it('nombra al titular por el contrato, con su documento tal cual', function () {
    $solicitud = ($this->crear)($this->titular);

    expect(EtiquetaDeTitular::deLaSolicitud($solicitud))->toBe('Rocío Paredes (11.111.111-1)');
});

it('un caso anonimizado se muestra como lo que es', function () {
    $solicitud = ($this->crear)(null);

    expect(EtiquetaDeTitular::estaAnonimizada($solicitud))->toBeTrue()
        ->and(EtiquetaDeTitular::deLaSolicitud($solicitud))->toBe('Caso anonimizado');
});

it('un titular huérfano no se muestra como una fila rota', function () {
    $solicitud = ($this->crear)($this->titular);
    $this->titular->forceDelete();
    $solicitud->unsetRelation('titular');

    expect(EtiquetaDeTitular::estaAnonimizada($solicitud))->toBeFalse()
        ->and(EtiquetaDeTitular::deLaSolicitud($solicitud))->toBe('Titular no disponible');
});

it('en el buscador el titular se nombra con guion', function () {
    expect(EtiquetaDeTitular::de($this->titular))->toBe('Rocío Paredes — 11.111.111-1')
        ->and(EtiquetaDeTitular::de(null))->toBeNull();
});
