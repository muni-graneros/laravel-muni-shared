<?php

use Muni\Shared\Persona\PersonaDTO;

it('construye desde array normalizando el RUT', function () {
    $dto = PersonaDTO::fromArray([
        'nro_documento' => '19.876.543-2',
        'nombres' => 'Jane', 'apellidos' => 'Doe',
        'source' => 'api', 'system' => 'api', 'tipo_documento' => 'RUN',
    ]);
    expect($dto->nroDocumento)->toBe('19876543-2')
        ->and($dto->nombres)->toBe('Jane')
        ->and($dto->source)->toBe('api');
});

it('source por defecto es api y toArray expone los campos', function () {
    $dto = PersonaDTO::fromArray([]);
    expect($dto->source)->toBe('api')->and($dto->system)->toBe('api')
        ->and($dto->toArray())->toBeArray()->toHaveKey('nro_documento');
});

it('mapea etiquetas de sistema (discapacidad y omil legado)', function () {
    expect(PersonaDTO::label('discapacidad'))->toBe('Discapacidad')
        ->and(PersonaDTO::label('omil'))->toBe('Discapacidad')
        ->and(PersonaDTO::label('feria'))->toBe('Feria Control')
        ->and(PersonaDTO::label('api'))->toBe('API Central de Personas')
        ->and(PersonaDTO::label('otro'))->toBe('Sistema Municipal');
});
