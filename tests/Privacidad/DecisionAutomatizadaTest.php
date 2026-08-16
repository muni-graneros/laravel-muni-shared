<?php

use Illuminate\Support\Facades\Artisan;
use Muni\Shared\Privacidad\BaseLicitud;
use Muni\Shared\Privacidad\Modelos\DecisionAutomatizada;
use Muni\Shared\Privacidad\Modelos\Finalidad;

beforeEach(function () {
    config(['privacidad.sistema' => 'discapacidad']);
    $this->finalidad = Finalidad::create([
        'sistema' => 'discapacidad', 'codigo' => 'registro_comunal', 'nombre' => 'Registro comunal',
        'base_licitud' => BaseLicitud::FuncionLegal, 'norma_habilitante' => 'Ley 20.422',
    ]);
});

it('registra una decisión automatizada con su lógica y consecuencias', function () {
    $decision = DecisionAutomatizada::create([
        'sistema' => 'discapacidad',
        'finalidad_id' => $this->finalidad->getKey(),
        'descripcion' => 'Priorización automática de la lista de espera',
        'logica' => 'Ordena por antigüedad de la solicitud y grado de discapacidad declarado.',
        'consecuencias' => 'Afecta el orden de atención, no el derecho a ser atendido.',
        'permite_revision_humana' => true,
    ]);

    expect($decision->permite_revision_humana)->toBeTrue();
});

it('el RAT en json las expone', function () {
    DecisionAutomatizada::create([
        'sistema' => 'discapacidad', 'finalidad_id' => $this->finalidad->getKey(),
        'descripcion' => 'Priorización de lista de espera', 'logica' => 'Antigüedad y grado.',
        'consecuencias' => 'Orden de atención.', 'permite_revision_humana' => true,
    ]);

    // Artisan::call()+output() y no $this->artisan(...): el mock de salida
    // de $this->artisan() no escribe al buffer que lee Artisan::output(),
    // como ya documenta ExportarRatTest para el mismo comando.
    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($rat['decisiones_automatizadas'])->toHaveCount(1)
        ->and($rat['decisiones_automatizadas'][0]['descripcion'])->toBe('Priorización de lista de espera');
});

it('declara explícitamente cuando el sistema no toma ninguna', function () {
    $codigo = Artisan::call('privacidad:rat', ['--json' => true]);

    expect($codigo)->toBe(0);

    $rat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    // Declarar que no hay es una respuesta, no un vacío.
    expect($rat['decisiones_automatizadas'])->toBe([]);
});

it('avisa en la tabla cuando una decisión no admite revisión humana', function () {
    DecisionAutomatizada::create([
        'sistema' => 'discapacidad', 'finalidad_id' => $this->finalidad->getKey(),
        'descripcion' => 'Rechazo automático', 'logica' => 'Regla dura.',
        'consecuencias' => 'Deniega el beneficio.', 'permite_revision_humana' => false,
    ]);

    $this->artisan('privacidad:rat')
        ->expectsOutputToContain('sin revisión humana')
        ->assertSuccessful();
});

it('declara explícitamente en el RAT legible cuando el sistema no toma ninguna', function () {
    // La distinción que le importa a una fiscalización: un RAT que no
    // menciona el tema es indistinguible de uno que se olvidó de revisarlo.
    // Uno que dice «no declara ninguna» contestó la pregunta.
    $this->artisan('privacidad:rat')
        ->expectsOutputToContain('no declara decisiones automatizadas')
        ->assertSuccessful();
});
